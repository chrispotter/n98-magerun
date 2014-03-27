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
Supported Locales:

- cs_CZ
- ru_RU
- bg_BG
- en_US
- it_IT
- sr_RS
- sr_Cyrl_RS
- sr_Latn_RS
- pl_PL
- en_GB
- de_DE
- sk_SK
- fr_FR
- es_AR
- de_AT
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
    protected function formatUSAddress($address){

        try{
            $rtnAddress['street'] = $address->streetAddress;
        }
        catch(\InvalidArgumentException $e){
            $rtnAddress['street'] = $address->street;
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
        $address = $this->formatUSAddress($faker);

        //get Magento Address to save to Customer
        $mageAddress = $this->_getModel('customer/address', 'Mage_Customer_Model_Address');
        $mageAddress->setCustomerId($customer->getId());
        $mageAddress->firstname = $customer->firstname;
        $mageAddress->lastname = $customer->lastname;
        $mageAddress->email = $customer->getEmail();
        $mageAddress->street = $address['street'];
        $mageAddress->country_id = explode("_",$input->getArgument('locale'))[1];
        $mageAddress->setIsDefaultBilling(true);
        $mageAddress->setIsDefaultShipping(true);

        $mageAddress->city = $address['city'];
        if($input->getArgument('locale') == "en_US"){
            $mageAddress->setRegionId(rand(0,50));
            $region = $this->_getModel('directory/region', 'Mage_Directory_Model_Region');
            $region->load($mageAddress->getRegionId());
            $mageAddress->setRegion($region->getName());
            $mageAddress->postcode = $address['postCode'];
        }
        else{
            $mageAddress->city = $address['city'];
            if(!$mageAddress == "BG" or !$mageAddress == "CZ"){
                $mageAddress->setRegion($address['state']);
            }
            $mageAddress->postcode = $address['postCode'];
        }

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