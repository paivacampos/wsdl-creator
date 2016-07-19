<?php
namespace WSDL\XML;

use DOMDocument;
use DOMElement;
use Ouzo\Utilities\Arrays;
use WSDL\Builder\Parameter;
use WSDL\Builder\WSDLBuilder;
use WSDL\Utilities\XMLAttributeHelper;
use WSDL\XML\XMLStyle\XMLStyle;
use WSDL\XML\XMLStyle\XMLStyleFactory;
use WSDL\XML\XMLUse\XMLUse;
use WSDL\XML\XMLUse\XMLUseFactory;

class XMLProvider
{
    /**
     * @var WSDLBuilder
     */
    private $builder;
    /**
     * @var XMLStyle
     */
    private $XMLStyle;
    /**
     * @var XMLUse
     */
    private $XMLUse;
    /**
     * @var DOMDocument
     */
    private $DOMDocument;
    /**
     * @var string
     */
    private $xml;
    /**
     * @var DOMDocument
     */
    private $definitionsRootNode;

    public function __construct(WSDLBuilder $builder)
    {
        $this->builder = $builder;
        $this->XMLStyle = XMLStyleFactory::create($builder->getStyle());
        $this->XMLUse = XMLUseFactory::create($builder->getUse());
        $this->DOMDocument = new DOMDocument("1.0", "UTF-8");
        $this->DOMDocument->formatOutput = true;
    }

    public function getXml()
    {
        $this->saveXML();
        return $this->xml;
    }

    private function saveXML()
    {
        $this->xml = $this->DOMDocument->saveXML();
    }

    public function generate()
    {
        $this->definitions()
            ->types()
            ->message()
            ->portType()
            ->binding()
            ->service();
    }

    private function definitions()
    {
        $targetNamespace = $this->builder->getTargetNamespace();
        $definitionsElement = $this->createElementWithAttributes('definitions', array(
            'name' => $this->builder->getName(),
            'targetNamespace' => $targetNamespace,
            'xmlns:tns' => $targetNamespace,
            'xmlns:xsd' => 'http://www.w3.org/2001/XMLSchema',
            'xmlns:soap' => 'http://schemas.xmlsoap.org/wsdl/soap/',
            'xmlns:soapenc' => "http://schemas.xmlsoap.org/soap/encoding/",
            'xmlns' => 'http://schemas.xmlsoap.org/wsdl/',
            'xmlns:ns' => $this->builder->getNs()
        ));
        $this->DOMDocument->appendChild($definitionsElement);
        $this->definitionsRootNode = $definitionsElement;
        return $this;
    }

    private function service()
    {
        $name = $this->builder->getName();
        $serviceElement = $this->createElementWithAttributes('service', array('name' => $name . 'Service'));

        $portElement = $this->createElementWithAttributes('port', array('name' => $name . 'Port', 'binding' => 'tns:' . $name . 'Binding'));

        $soapAddressElement = $this->createElementWithAttributes('soap:address', array('location' => $this->builder->getLocation()));
        $portElement->appendChild($soapAddressElement);

        $serviceElement->appendChild($portElement);
        $this->definitionsRootNode->appendChild($serviceElement);
        return $this;
    }

    private function binding()
    {
        $name = $this->builder->getName();
        $targetNamespace = $this->builder->getTargetNamespace();
        $bindingElement = $this->createElementWithAttributes('binding', array('name' => $name . 'Binding', 'type' => 'tns:' . $name . 'PortType'));

        $soapBindingElement = $this->XMLStyle->generateBinding($this->DOMDocument);
        $bindingElement->appendChild($soapBindingElement);

        foreach ($this->builder->getMethods() as $method) {
            $methodName = $method->getName();
            $operationElement = $this->createElementWithAttributes('operation', array('name' => $methodName));
            $soapOperationElement = $this->createElementWithAttributes('soap:operation', array(
                'soapAction' => $targetNamespace . '/#' . $methodName
            ));
            $operationElement->appendChild($soapOperationElement);

            $soapBodyElement = $this->XMLUse->generateSoapBody($this->DOMDocument, $targetNamespace);
            $this->bindingElement($methodName, $soapBodyElement, $operationElement, 'input', 'RequestHeader', $method->getHeaderParameter());
            $this->bindingElement($methodName, $soapBodyElement, $operationElement, 'output', 'ResponseHeader', $method->getHeaderReturn());

            $bindingElement->appendChild($operationElement);
        }

        $this->definitionsRootNode->appendChild($bindingElement);
        return $this;
    }

    private function bindingElement($methodName, DOMElement $soapBodyElement, DOMElement $element, $elementName, $headerName, $header)
    {
        $targetNamespace = $this->builder->getTargetNamespace();
        $inputElement = $this->createElement($elementName);
        $inputElement->appendChild($soapBodyElement->cloneNode());

        $soapHeaderMessage = 'tns:' . $methodName . $headerName;
        $soapHeaderElement = $this->XMLUse
            ->generateSoapHeaderIfNeeded($this->DOMDocument, $targetNamespace, $soapHeaderMessage, $header);
        if ($soapHeaderElement) {
            $inputElement->appendChild($soapHeaderElement);
        }

        $element->appendChild($inputElement);
    }

    private function portType()
    {
        $name = $this->builder->getName();
        $portTypeElement = $this->createElementWithAttributes('portType', array('name' => $name . 'PortType'));

        foreach ($this->builder->getMethods() as $method) {
            $methodName = $method->getName();
            $operationElement = $this->createElementWithAttributes('operation', array('name' => $methodName));

            $inputElement = $this->createElementWithAttributes('input', array('message' => 'tns:' . $methodName . 'Request'));
            $operationElement->appendChild($inputElement);

            $outputElement = $this->createElementWithAttributes('output', array('message' => 'tns:' . $methodName . 'Response'));
            $operationElement->appendChild($outputElement);

            $portTypeElement->appendChild($operationElement);
        }

        $this->definitionsRootNode->appendChild($portTypeElement);
        return $this;
    }

    private function message()
    {
        foreach ($this->builder->getMethods() as $method) {
            $name = $method->getName();

            $this->messageHeaderIfNeeded($name, 'RequestHeader', $method->getHeaderParameter());
            $messageInputElement = $this->messageParts($name . 'Request', $method->getNoHeaderParametersNodes());
            $this->definitionsRootNode->appendChild($messageInputElement);

            $this->messageHeaderIfNeeded($name, 'ResponseHeader', $method->getHeaderReturn());
            $messageOutputElement = $this->messageParts($name . 'Response', $method->getReturnNode());
            $this->definitionsRootNode->appendChild($messageOutputElement);
        }
        return $this;
    }

    private function messageHeaderIfNeeded($method, $headerSuffix, Parameter $parameter = null)
    {
        if ($parameter) {
            $messageHeaderElement = $this->messageParts($method . $headerSuffix, $parameter->getNode());
            $this->definitionsRootNode->appendChild($messageHeaderElement);
        }
    }

    private function messageParts($name, $nodes)
    {
        $nodes = Arrays::toArray($nodes);
        $messageElement = $this->createElementWithAttributes('message', array('name' => $name));
        $parts = $this->XMLStyle->generateMessagePart($this->DOMDocument, $nodes);
        foreach ($parts as $part) {
            $messageElement->appendChild($part);
        }
        return $messageElement;
    }

    private function types()
    {
        $ns = $this->builder->getNs();
        $typesElement = $this->createElement('types');

        $schemaElement = $this->createElementWithAttributes('xsd:schema', array('targetNamespace' => $ns, 'xmlns' => $ns));
        $typesElement->appendChild($schemaElement);

        $this->definitionsRootNode->appendChild($typesElement);
        return $this;
    }

    private function createElementWithAttributes($elementName, $attributes, $value = '')
    {
        return XMLAttributeHelper::forDOM($this->DOMDocument)->createElementWithAttributes($elementName, $attributes, $value);
    }

    private function createElement($elementName, $value = '')
    {
        return XMLAttributeHelper::forDOM($this->DOMDocument)->createElement($elementName, $value);
    }
}
