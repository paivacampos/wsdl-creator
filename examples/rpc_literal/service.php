<?php
use Ouzo\Utilities\Arrays;
use Ouzo\Utilities\Functions;
use WSDL\Annotation\BindingType;
use WSDL\Annotation\SoapBinding;
use WSDL\Builder\Method;
use WSDL\Builder\Parameter;
use WSDL\Builder\WSDLBuilder;
use WSDL\Lexer\Tokenizer;
use WSDL\WSDL;

require_once '../../vendor/autoload.php';

ini_set("soap.wsdl_cache_enabled", 0);

$tokenizer = new Tokenizer();

$parameters1 = [Parameter::fromTokens($tokenizer->lex('string $userName'))];
$return1 = Parameter::fromTokens($tokenizer->lex('string $uppercasedUserName'));

$parameters2 = [
    Parameter::fromTokens($tokenizer->lex('int[] $numbers')),
    Parameter::fromTokens($tokenizer->lex('string $prefix'))
];
$return2 = Parameter::fromTokens($tokenizer->lex('string[] $numbersWithPrefix'));

$parameters3 = [Parameter::fromTokens($tokenizer->lex('object $user { string $name int $age }'))];
$return3 = Parameter::fromTokens($tokenizer->lex('object $userContext { int $id object $userInfo { string $name int $age } }'));

$parameters4 = [Parameter::fromTokens($tokenizer->lex('object[] $companies { string $name int $postcode }'))];
$return4 = Parameter::fromTokens($tokenizer->lex('string[] $companiesNames'));

$parameters5 = [Parameter::fromTokens($tokenizer->lex('string[] $errors'))];
$return5 = Parameter::fromTokens($tokenizer->lex('object $result { bool $result string[] $errors }'));

$builder = WSDLBuilder::instance()
    ->setName('RpcLiteralService')
    ->setTargetNamespace('http://foo.bar/rpcliteralservice')
    ->setNs('http://foo.bar/rpcliteralservice/types')
    ->setLocation('http://localhost:7777/wsdl-creator/examples/rpc_literal/service.php')
    ->setStyle(SoapBinding::RPC)
    ->setUse(SoapBinding::LITERAL)
    ->setSoapVersion(BindingType::SOAP_11)
    ->setMethod(new Method('uppercaseUserName', $parameters1, $return1))
    ->setMethod(new Method('appendPrefixToNumbers', $parameters2, $return2))
    ->setMethod(new Method('getUserContext', $parameters3, $return3))
    ->setMethod(new Method('extractCompaniesNames', $parameters4, $return4))
    ->setMethod(new Method('wrapErrors', $parameters5, $return5));

$wsdl = WSDL::fromBuilder($builder);

if (isset($_GET['wsdl'])) {
    header("Content-Type: text/xml");
    echo $wsdl->create();
    exit;
}
$server = new SoapServer('http://localhost:7777/wsdl-creator/examples/rpc_literal/service.php?wsdl', [
    'uri' => $builder->getTargetNamespace(),
    'location' => $builder->getLocation(),
    'style' => SOAP_RPC,
    'use' => SOAP_LITERAL
]);
$server->setClass('RpcLiteralService');
$server->handle();

class RpcLiteralService
{
    public function uppercaseUserName($userName)
    {
        return strtoupper($userName);
    }

    public function appendPrefixToNumbers($numbers, $prefix)
    {
        return Arrays::map($numbers, Functions::prepend($prefix));
    }

    public function getUserContext($user)
    {
        $userContext = new stdClass();
        $userContext->id = time();
        $userContext->UserInfo = new stdClass();
        $userContext->UserInfo->name = $user->name;
        $userContext->UserInfo->age = $user->age;
        return $userContext;
    }

    public function extractCompaniesNames($companies)
    {
        return Arrays::map($companies, Functions::extractField('name'));
    }
}