<?php

// Conduction/BalanceBundle/Service/BalanceService.php
namespace Conduction\BalanceBundle\Service;


use Conduction\CommonGroundBundle\Service\CommonGroundService;
use Money\Money;

class BalanceService
{

    /**
     * @var CommonGroundService
     */
    private $commonGroundService;

    public function __construct(
        CommonGroundService $commonGroundService
    ) {
        $this->commonGroundService = $commonGroundService;
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
        $amount = $amount->getAmount()*100;
        $this->commonGroundService->createResource(["credit"=>$amount,"resource"=>$resource,"name"=>$name], ["component"=>"bare","type"=>"payments"]);

        return true;
    }

    /**
     * This function removes money from an acount
     *
     * @parameter $amount Money the amount of money that you want to remove
     * @parameter $resource string resource that owns the acount that is being removed from
     * @parameter $name string the text displayed with this transaction
     *
     * @returns boolean true if the transaction was succesfull, false otherwise
     */
    public function removeCredit(Money $amount, string $resource, string $name)
    {
        // If the resource has no acount it can't have a credit limit, so we wont be able to remove money
        if(!$acount = $this->getAcount($resource)){
            return false;
        }

        // Lets see if the transaction would pass the credit limit
        $newBalance = $acount['balance'] - $amount->getAmount()/100;
        if(abs($newBalance) >  $acount['creditLimit']){
            return false;
        }

        $amount = $amount->getAmount()*100;
        $this->commonGroundService->createResource(["debit"=>$amount,"resource"=>$resource,"name"=>$name], ["component"=>"bare","type"=>"payments"]);

        return true;
    }

    /**
     * This function give the current balance from an acount
     *
     * @parameter $resource string of the acount that needs to be collected
     *
     * @returns Money the current balance of an acount, EUR 0 if no balance could be established
     */
    public function getBalance(string $resource)
    {
        if($acount = $this->getAcount($resource)){
            $amount = $acount['balance']/100;
            return new Money($amount, new Currency($acount['currency']));
        }
        else{
            return new Money(0, new Currency('EUR'));
        }

    }

    /**
     * Get the primarry acount for a given resource
     *
     * @parameter $acount string the acount that needs to be collected
     *
     * @returns Array|False the acount if found or false otherwise
     */
    public function getAcount(string $resource)
    {
        $list = $this->commonGroundService->getResourceList(["component"=>"bare","type"=>"acounts"],["resource"=>"bare",$resource]);

        if(count($list) > 0){
           return $list[0];
        }
        else{
           return false;
        }
    }
}
