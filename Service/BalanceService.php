<?php

// Conduction/BalanceBundle/Service/BalanceService.php
namespace Conduction\BalanceBundle\Service;


use Conduction\CommonGroundBundle\Service\CommonGroundService;
use GuzzleHttp\Client;
use Money\Money;
use Money\Currency;
use Money\Currencies\ISOCurrencies;
use Money\Formatter\IntlMoneyFormatter;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class BalanceService
{

    /**
     * @var CommonGroundService
     */
    private $commonGroundService;

    /**
     * @var ParameterBagInterface
     */
    private $params;

    public function __construct(
        CommonGroundService $commonGroundService,
        ParameterBagInterface $params
    ) {
        $this->commonGroundService = $commonGroundService;
        $this->params = $params;
    }

    /**
     * This function add money to an acount
     *
     * @parameter $amount Money the amount of money that you want to add
     * @parameter $resource string resource that owns the acount that is being added to
     * @parameter $name string the text displayed with this transaction
     *
     * @returns boolean true if the transaction was succesfull, false otherwise
     */
    public function addCredit(Money $amount, string $resource, string $name)
    {
        // We can actually always add money, so no checks requered here
        $amount = (int)$amount->getAmount();
        $this->commonGroundService->createResource(["credit"=>$amount,"resource"=>$resource,"name"=>$name], ["component"=>"bare","type"=>"payments"]);

        return true;
    }

    /**
     * This function removes money from an acount
     *
     * @param Money $amount the amount of money that you want to remove.
     * @param string $resource resource that owns the acount that is being removed from.
     * @param string $name the text displayed with this transaction.
     *
     * @return true|false true if the transaction was succesfull, false otherwise
     */
    public function removeCredit(Money $amount, string $resource, string $name)
    {
        // If the resource has no acount it can't have a credit limit, so we wont be able to remove money
        if(!$acount = $this->getAcount($resource)){
            return false;
        }

        // Lets see if the transaction would pass the credit limit
        $newBalance = $acount['balance'] - (int)$amount->getAmount();
        if(abs($newBalance) <  $acount['creditLimit']){
            return false;
        }

        $amount = (int)$amount->getAmount();

        $this->commonGroundService->createResource(["debit"=>$amount,"resource"=>$resource,"name"=>$name], ["component"=>"bare","type"=>"payments"]);

        return true;
    }

    /**
     * This function give the current balance from an acount
     *
     * @param string $resource string of the acount that needs to be collected
     *
     * @return Money the current balance of an acount, EUR 0 if no balance could be established
     */
    public function getBalance(string $resource)
    {
        if($acount = $this->getAcount($resource)){
            $amount = $acount['balance'];

            $currencies = new ISOCurrencies();

            $numberFormatter = new \NumberFormatter('en_EU', \NumberFormatter::CURRENCY);
            $moneyFormatter = new IntlMoneyFormatter($numberFormatter, $currencies);

            return $moneyFormatter->format(new Money($amount, new Currency($acount['currency'])));
        }
        else{
            return new Money(0, new Currency('EUR'));
        }

    }

    /**
     * Get the primarry acount for a given resource
     *
     * @param string $acount the acount that needs to be collected
     *
     * @return Array|False the acount if found or false otherwise
     */
    public function getAcount(string $resource)
    {
        $list = $this->commonGroundService->getResourceList(["component"=>"bare","type"=>"acounts"],["resource"=> $resource])['hydra:member'];

        if(count($list) > 0){
            return $list[0];
        }
        else{
            return false;
        }
    }

    /**
     * Creates account with the provided uri.
     * This function retrieves the object from the uri.
     * And uses the uri as resource and name of object as name of the account.
     *
     *
     * @param string $resource uri of the resource
     * @param int $balance (optional) the balace you wish to give to the new account, 1 euro = 100
     *
     * @return Array|False the acount if created or false otherwise
     */
    public function createAccount(string $resource, $balance = null)
    {
        if (filter_var($resource, FILTER_VALIDATE_URL)) {
            try {
                $resourceObject = $this->commonGroundService->getResource($resource);

                $validChars = '0123456789';
                $reference = substr(str_shuffle(str_repeat($validChars, ceil(3 / strlen($validChars)))), 1, 10);

                $account = [];
                $account['resource'] = $resource;
                $account['reference'] = $reference;
                $account['name'] = $resourceObject['name'];

                $account = $this->commonGroundService->createResource($account, ['component' => 'bare', 'type' => 'acounts']);

                if ($balance !== null) {
                    $this->addCredit(Money::EUR($balance), $resource, $resourceObject['name']);
                }

                return $account;
            } catch (\Throwable $e) {
                return false;
            }
        } else {
            return false;
        }

    }

    /**
     * This function generates an payment url and mollie id and returns it.
     *
     * @param string $amount amount of money that needs to be paid.
     * @param string $redirectUrl url where mollie needs to send user after payment.
     *
     * @return array|false array containing redirectUrl (where we send the user to) and id (which we use in processMolliePayment function).
     */
    public function createMolliePayment(string $amount, string $redirectUrl)
    {
        $body = [
            'amount'      => [
                'currency' => 'EUR',
                'value'    => $amount,
            ],
            'description' => 'funds for Checking.nu',
            'redirectUrl' => $redirectUrl,
            'locale'      => 'en_US',
        ];

        $headers = [
            'Authorization' => 'Bearer '.$this->params->get('app_mollie_key'),
            'Accept'        => 'application/json',
        ];

        $client = new Client([
            // Base URI is used with relative requests
            'base_uri' => 'https://api.mollie.com',
            // You can set any number of default request options.
            'timeout'  => 2.0,
        ]);

        $response = $client->request('POST', '/v2/payments', [
            'form_params'  => $body,
            'content_type' => 'application/x-www-form-urlencoded',
            'headers'      => $headers,
        ]);

        $response = json_decode($response->getBody()->getContents(), true);

        $info = [];

        $info['id'] = $response['id'];
        $info['redirectUrl'] = $response['_links']['checkout']['href'];

        return $info;
    }

    /**
     * This function gets information of the payment and if paid adds funds to account & creates an invoice.
     *
     * @param string $id id provided by mollie.
     * @param string $resource uri of the resource
     *
     * @return array array containing if successful: amount(amount added to account in whole euros eg: 10), reference(reference number of invoice) and status(payment status) if payment not successful it returns object with status(payment status)
     */
    public function processMolliePayment(string $id, string $resource)
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->params->get('app_mollie_key'),
            'Accept'        => 'application/json',
        ];

        $client = new Client([
            // Base URI is used with relative requests
            'base_uri' => 'https://api.mollie.com',
            // You can set any number of default request options.
            'timeout'  => 2.0,
        ]);

        $response = $client->request('GET', '/v2/payments/'.$id, [
            'headers' => $headers,
        ]);

        $response = json_decode($response->getBody()->getContents(), true);

        if ($response['status'] == 'paid') {
            $resourceObject = $this->commonGroundService->getResource($resource);

            $amount = ($response['amount']['value'] / (1 + .21)) * 100;
            $this->addCredit(Money::EUR($amount), $resource, $resourceObject['name']);

            $item = [];
            $item['name'] = 'Checking Credit';
            $item['quantity'] = 1;
            $item['price'] = strval($amount / 100);
            $item['priceCurrency'] = 'EUR';

            $invoice = [];
            $invoice['customer'] = $resource;
            $invoice['name'] = 'Checking wallet funds';
            $invoice['items'][] = $item;
            $invoice['targetOrganization'] = $resource;
            $invoice['price'] = strval($amount / 100);
            $invoice['priceCurrency'] = 'EUR';
            $invoice['paid'] = true;
            $invoice['reference'] = Uuid::uuid4();

            $invoice = $this->commonGroundService->createResource($invoice, ['component' => 'bc', 'type' => 'invoices']);

            $templateAmount = $amount / 100;

            $object['amount'] = $templateAmount;
            $object['reference'] = $invoice['reference'];
            $object['status'] = $response['status'];

            return $object;
        } else {
            $object['status'] = $response['status'];
            return $object;
        }
    }
}
