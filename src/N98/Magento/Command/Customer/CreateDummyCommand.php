<?php

namespace N98\Magento\Command\Customer;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use N98\Util\Console\Helper\Table\Renderer\RendererFactory;
use Symfony\Component\Validator\Exception\InvalidArgumentException;

class CreateDummyCommand extends AbstractCustomerCommand
{
    protected function configure()
    {
        $help = <<<HELP
Supported Locales:      (a) signifies address support

- cs_CZ (a)
- ru_RU
- bg_BG (a)
- en_US (a)
- it_IT (a)
- sr_RS (a)
- sr_Cyrl_RS    (a)
- sr_Latn_RS    (a)
- pl_PL (a)
- en_GB (a)
- de_DE (a)
- sk_SK (a)
- fr_FR (a)
- es_AR (a)
- de_AT (a)
HELP;

        $this
            ->setName('customer:create:dummy')
            ->addArgument('count', InputArgument::REQUIRED, 'Count')
            ->addArgument('locale', InputArgument::REQUIRED, 'Locale')
            ->addArgument('website', InputArgument::OPTIONAL, 'Website')
            ->addOption('address', 'a', InputOption::VALUE_NONE, 'Generates random Address information for generated customers(US Locale Only)')
            ->addOption('password', 'p', InputOption::VALUE_OPTIONAL, 'Sets all created customers to have the specified password')
            ->setDescription('Generate dummy customers. You can specify a count and a locale.')
            ->addOption(
                'format',
                null,
                InputOption::VALUE_OPTIONAL,
                'Output Format. One of [' . implode(',', RendererFactory::getFormats()) . ']'
            )
            ->setHelp($help)
        ;
    }
    protected function formatAddress($address){

        try{
            $rtnAddress['street'] = $address->streetAddress;
        }
        catch(\InvalidArgumentException $e){
            $rtnAddress['street'] = rand(0,100). ' ' . $address->street;
        }

        $rtnAddress['postCode'] = $address->postcode;
        $rtnAddress['city'] = $address->city;
        try{
            $rtnAddress['state'] = $address->state;
        }catch(\InvalidArgumentException $e){
            $rtnAddress['state'] = '';
        }

        return $rtnAddress;
    }

    protected function setAddress($customer, $faker, $input){
        $address = $this->formatAddress($faker);

        //get Magento Address to save to Customer
        $mageAddress = $this->_getModel('customer/address', 'Mage_Customer_Model_Address');
        $mageAddress->setCustomerId($customer->getId());
        $mageAddress->firstname = $customer->firstname;
        $mageAddress->lastname = $customer->lastname;
        $mageAddress->email = $customer->getEmail();
        $mageAddress->street = $address['street'];

        $mageAddress->country_id = end(explode("_",$input->getArgument('locale')));

        $mageAddress->setIsDefaultBilling(true);
        $mageAddress->setIsDefaultShipping(true);

        $mageAddress->city = $address['city'];

        //Determine if Locale is supported by Installation of Magento, If so, set region and region Id from random
        //chosen region.  Else, use the randomly generated region.
        $countryCollection= $this->_getModel('directory/country_api', 'Mage_Directory_Model_Country_Api')->items();
        if(in_array($mageAddress->country_id, $countryCollection)){
            $regionCollection = $this->_getModel('directory/region_api', 'Mage_Directory_Model_Region_Api')->items($mageAddress->country_id);
            $regionId= $regionCollection[array_rand($regionCollection,1)]['region_id'];
            $region = $this->_getModel('directory/region', 'Mage_Directory_Model_Region')->load($regionId);
            $mageAddress->setRegionId($region->getRegionId());
            $mageAddress->setRegion($region->getDefaultName());
        }
        else{
            //cs_CZ, bg_BG and pl_PL return United States state names.
            if($mageAddress->country_id != "CZ" and
               $mageAddress->country_id!= "BG" and
               $mageAddress->country_id!= "PL"){
                $mageAddress->setRegion($address['state']);
            }
        }
        $mageAddress->postcode = $address['postCode'];

        //Set Telephone Number
        $mageAddress->telephone = $faker->phoneNumber;
        $mageAddress->save();
    }
    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->detectMagento($output, true);
        if ($this->initMagento()) {
            
            $res = $this->getCustomerModel()->getResource();
            
            $faker = \Faker\Factory::create($input->getArgument('locale'));
            $faker->addProvider(new \N98\Util\Faker\Provider\Internet($faker));

            $website = $this->getHelperSet()->get('parameter')->askWebsite($input, $output);

            $res->beginTransaction();
            $count = $input->getArgument('count');
            $outputPlain = $input->getOption('format') === null;

            $table = array();
            for ($i = 0; $i < $count; $i++) {
                $customer = $this->getCustomerModel();

                $email = $faker->safeEmail;

                $customer->setWebsiteId($website->getId());
                $customer->loadByEmail($email);
                $password = $input->getOption('password');
                if(!isset($password)){
                    $password = $customer->generatePassword();
                }

                if (!$customer->getId()) {
                    $customer->setWebsiteId($website->getId());
                    $customer->setEmail($email);
                    $customer->setFirstname($faker->firstName);
                    $customer->setLastname($faker->lastName);
                    $customer->setPassword($password);



                    $customer->save();
                    $customer->setConfirmation(null);
                    $customer->save();

                    if($input->getOption('address')){
                        $this->setAddress($customer, $faker, $input);
                    }

                    if ($outputPlain) {
                        $output->writeln('<info>Customer <comment>' . $email . '</comment> with password <comment>' . $password .  '</comment> successfully created</info>');
                    } else {
                        $table[] = array(
                            $email, $password, $customer->getFirstname(), $customer->getLastname(),
                        );
                    }
                } else {
                    if ($outputPlain) {
                        $output->writeln('<error>Customer ' . $email . ' already exists</error>');
                    }
                }
                if ($i % 1000 == 0) {
                    $res->commit();
                    $res->beginTransaction();
                }
            }
            $res->commit();

            if (!$outputPlain) {
                $this->getHelper('table')
                    ->setHeaders(array('email', 'password', 'firstname', 'lastname'))
                    ->renderByFormat($output, $table, $input->getOption('format'));
            }

        }
    }
}