<?php

namespace Drupal\commerce_avatax\OrderProcessor;

use Drupal\commerce_avatax\Resolver\ChainTaxCodeResolverInterface;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderProcessorInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\Exception\ClientException;

class Avatax implements OrderProcessorInterface {

  protected $config;

  protected $client;

  protected $dateFormatter;

  protected $chainTaxCodeResolver;

  protected $moduleHandler;

  protected $logger;

  public function __construct(ConfigFactoryInterface $config_factory, ClientFactory $client_factory, DateFormatterInterface $date_formatter, ChainTaxCodeResolverInterface $chain_tax_code_resolver, ModuleHandlerInterface $module_handler, LoggerChannelFactoryInterface $logger_factory) {
    $this->config = $config_factory->get('commerce_avatax.settings');
    $this->client = $client_factory->fromOptions([
      'headers' => [
        'Authorization' => 'Basic ' . base64_encode($this->config->get('api_key') . ':' . $this->config->get('license_key')),
        'Content-Type' => 'application/json',
        'x-Avalara-UID' => $this->config->get('api_uid'),
      ],
    ]);
    $this->dateFormatter = $date_formatter;
    $this->chainTaxCodeResolver = $chain_tax_code_resolver;
    $this->moduleHandler = $module_handler;
    $this->logger = $logger_factory->get('commerce_avatax');
  }

  /**
   * {@inheritdoc}
   */
  public function process(OrderInterface $order) {
    $config = $this->config;

    // Attempt to get company code for specific store.
    $store = $order->getStore();
    if ($store->get('avatax_company_code')->isEmpty()) {
      $company_code = $config->get('company_code');
    }
    else {
      $company_code = $store->avatax_company_code->value;
    }

    $request_body = [
      'DocType' => 'SalesInvoice',
      'CompanyCode' => $company_code,
      'DocDate' => $this->dateFormatter->format(REQUEST_TIME, 'custom', 'Y-m-d'),
      'DocCode' => 'DC-' . $order->id(),
      'CustomerCode' => $order->getEmail(),
      'CurrencyCode' => $order->getTotalPrice()->getCurrencyCode(),
      'Addresses' => [],
      'Lines' => []
    ];

    $addresses = [];
    if ($order->getBillingProfile()) {
      /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $address */
      $address = $order->getBillingProfile()->get('address')->first();
      $addresses = [
        [
          'AddressCode' => '1',
          'Line1' => $address->getAddressLine1(),
          'Line2' => $address->getAddressLine2(),
          'City' => $address->getLocality(),
          'Region' => $address->getAdministrativeArea(),
          'Country' => $address->getCountryCode(),
          'PostalCode' => $address->getPostalCode(),
        ],
      ];
    }
    $this->moduleHandler->alter('commerce_avatax_order_addresses', $addresses, $order);

    if (empty($addresses)) {
      return;
    }

    $request_body['addresses'] = $addresses;

    foreach ($order->getItems() as $item) {
      $request_body['Lines'][] = [
        'LineNo' => $item->id(),
        'Qty' => $item->getQuantity(),
        'Amount' => $item->getAdjustedPrice()->getNumber(),
        'OriginCode' => '1',
        'DestinationCode' => $this->chainTaxCodeResolver->resolve($item->getPurchasedEntity()),
      ];
    }

    $order_discount = new Price('0.00', $order->getTotalPrice()->getCurrencyCode());
    foreach ($order->getAdjustments() as $adjustment) {
      $order_discount = $order_discount->add($adjustment->getAmount());
    }
    $request_body['Discount'] = abs($order_discount->getNumber());
    $request_body['discount'] = abs($order_discount->getNumber());

    $this->moduleHandler->alter('commerce_avatax_order_request', $request_body, $order);

    // @todo check mode, this is hardcoded sandbox.
    try {
      $response = $this->client->post('https://development.avalara.net/1.0/tax/get', [
        'json' => $request_body,
      ]);

      $body = json_decode($response->getBody()->getContents(), TRUE);
      $order->addAdjustment(new Adjustment([
        'type' => 'sales_tax',
        'label' => 'Sales tax',
        'amount' => new Price((string) $body['TotalTax'], $order->getTotalPrice()->getCurrencyCode())
      ]));
    }
    catch (ClientException $e) {
      // @todo port error handling from D7.
      $this->logger->error($e->getResponse()->getBody()->getContents());
    }
    catch (\Exception $e) {
      $this->logger->error($e->getMessage());
    }
  }

}
