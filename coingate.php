<?php
/**
 * Coingate Payment Gateway
 *
 * Allows users to pay with Bitcoins and Altcoins
 *
 * @package blesta
 * @subpackage blesta.components.gateways.nonmerchant.coingate
 * @author CoinGate
 * @copyright Copyright (c) 2018, CoinGate
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @license http://github.com/coingate/blesta-plugin/blob/master/LICENSE
 * @link http://www.blesta.com/ Blesta
 * @link https://coingate.com Coingate
 */
class Coingate extends NonmerchantGateway
{
    private static $version = "1.0.0";
    private static $authors = [['name' => 'Coingate', 'url' => 'https://coingate.com']];
    private $meta;
    public function __construct()
    {
        Loader::loadComponents($this, array("Input"));

        Loader::loadModels($this, ['Clients']);

        Language::loadLang("coingate", null, dirname(__FILE__) . DS . "language" . DS);
    }

    public function getName()
    {
        return Language::_("Coingate.name", true);
    }

    public function getVersion()
    {
        return self::$version;
    }

    public function getAuthors()
    {
        return self::$authors;
    }

    public function getCurrencies()
    {
        return array("EUR", "GBP", "USD", "BTC", "PLN", "CZK", "SEK", "NOK", "DKK", "CHF", "ZAR", "AUD", "JPY", "BRL", "CAD", "CNY", "HKD", "HUF", "INR", "RUB", "ILS", "MYR", "MXN", "SGD", "RON", "VEF", "IDR", "PHP", "ARS", "THB", "NGN", "COP", "PKR", "AED", "UAH", "BGN");
    }

    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    public function getSettings(array $meta = null)
    {
        $this->view = $this->makeView("settings", "default", str_replace(ROOTWEBDIR, "", dirname(__FILE__) . DS));

        Loader::loadHelpers($this, array("Form", "Html"));

        $receive_currency = [
            'BTC' => Language::_('Coingate.receive_currency.btc', true),
            'EUR' => Language::_('Coingate.receive_currency.eur', true),
            'USD' => Language::_('Coingate.receive_currency.usd', true),
        ];

        $coingate_environment = [
            'sandbox' => Language::_('Coingate.environment.sandbox', true),
            'live' => Language::_('Coingate.environment.live', true),
        ];

        $this->view->set('meta', $meta);
        $this->view->set('receive_currency', $receive_currency);
        $this->view->set('coingate_environment', $coingate_environment);

        return $this->view->fetch();
    }

    public function editSettings(array $meta)
    {
        $rules = [
            'app_id'     => [
                'valid' => [
                    'rule'    => "isEmpty",
                    'negate'  => true,
                    'message' => Language::_("Coingate.!error.api.id.valid", true),
                ],
            ],
            'api_key'    => [
                'valid' => [
                    'rule'    => "isEmpty",
                    'negate'  => true,
                    'message' => Language::_("Coingate.!error.api.key.valid", true),
                ],
            ],
            'api_secret' => [
                'valid' => [
                    'rule'    => "isEmpty",
                    'negate'  => true,
                    'message' => Language::_("Coingate.!error.api.secret.valid", true),
                ],
            ],
        ];

        $this->Input->setRules($rules);

        $this->Input->validates($meta);

        return $meta;
    }

    public function encryptableFields()
    {
        return ['app_id', 'api_key', 'api_secret'];
    }

    public function setMeta(array $meta = null)
    {
        $this->meta = $meta;
    }

    public function buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)
    {
        Loader::load(dirname(__FILE__) . DS . 'coingate-php' . DS . 'init.php');

        $client_id = $this->ifSet($contact_info['client_id']);

        if (isset($invoice_amounts) && is_array($invoice_amounts)) {
            $invoices = $this->serializeInvoices($invoice_amounts);
        }

        $record = new Record();
        $company_name = $record->select("name")->from("companies")->where("id", "=", 1)->fetch();

        $orderId = $client_id . '@' . (!empty($invoices) ? $invoices : time());
        $token = md5($orderId);

        $callbackURL = Configure::get('Blesta.gw_callback_url')
        . Configure::get('Blesta.company_id') . '/coingate/?client_id='
        . $this->ifSet($contact_info['client_id']) . '&token=' . $token;

        $test_mode = $this->coingateEnvironment();

        $post_params = array(
            'order_id'         => $orderId,
            'price'            => $this->ifSet($amount),
            'description'      => $this->ifSet($options['description']),
            'title'            => $company_name->name . " " .$this->ifSet($options['description']),
            'token'            => $token,
            'currency'         => $this->ifSet($this->currency),
            'receive_currency' => $this->meta['receive_currency'],
            'callback_url'     => $callbackURL,
            'cancel_url'       => $this->ifSet($options['return_url']),
            'success_url'      => $this->ifSet($options['return_url']),
        );
    
        $order = \CoinGate\Merchant\Order::create($post_params, array(), array(
            'environment' => $test_mode,
            'app_id'      => $this->meta['app_id'],
            'api_key'     => $this->meta['api_key'],
            'api_secret'  => $this->meta['api_secret'],
            'user_agent'  => 'CoinGate - Blesta v' .BLESTA_VERSION . ' Extension v' . $this->getVersion(),

        ));

        if ($order && $order->payment_url) {
            header("Location: " . $order->payment_url);
        } else {
            print_r($order);
        }
    }

    public function validate(array $get, array $post)
    {
        $this->log($this->ifSet($_SERVER['REQUEST_URI']), serialize($post), "output", true);

        $cgOrder = $this->coingateCallback($this->ifSet($post['id']));

        $data_parts = explode('@', $this->ifSet($post['order_id']), 2);

        $client_id = $data_parts[0];

        $invoices = $this->ifSet($data_parts[1]);

        if (is_numeric($invoices)) {
            $invoices = null;
        }

        $orderId = $post['order_id'];
        $token = md5($orderId);

        if (empty($get['token']) || strcmp($get['token'], $token) !== 0) {
                $error_message = 'CoinGate Token: ' . $get['token'] . ' is not valid';
                $this->log($this->ifSet($_SERVER['REQUEST_URI']), $error_message, "output", true);
                throw new Exception($error_message);
        }

        $status = $this->statusChecking($post['id']);

        return [
            'client_id'      => $client_id,
            'amount'         => $this->ifSet($post['price']),
            'currency'       => $this->ifSet($post['currency']),
            'status'         => $status,
            'reference_id'   => null,
            'transaction_id' => $this->ifSet($post['id']),
            'invoices'       => $this->unserializeInvoices($invoices),
        ];
    }

    public function success(array $get, array $post)
    {

        $data_parts = explode('@', $this->ifSet($post['order_id']), 2);

        $client_id = $data_parts[0];

        $invoices = $this->ifSet($data_parts[1]);

        if (is_numeric($invoices)) {
            $invoices = null;
        }

        $orderId = $post['order_id'];
        $token = md5($orderId);

        if (empty($get['token']) || strcmp($get['token'], $token) !== 0) {
                $error_message = 'CoinGate Token: ' . $get['token'] . ' is not valid';
                $this->log($this->ifSet($_SERVER['REQUEST_URI']), $error_message, "output", true);
                throw new Exception($error_message);
        }

        $status = $this->statusChecking($post['id']);

        return [
            'client_id'      => $client_id,
            'amount'         => $this->ifSet($post['price']),
            'currency'       => $this->ifSet($post['currency']),
            'status'         => $status,
            'transaction_id' => $this->ifSet($post['id']),
            'invoices'       => $this->unserializeInvoices($invoices),
        ];
    }

    public function capture($reference_id, $transaction_id, $amount, array $invoice_amounts = null)
    {
        $this->Input->setErrors($this->getCommonError("unsupported"));
    }

    public function void($reference_id, $transaction_id, $notes = null)
    {
        $this->Input->setErrors($this->getCommonError("unsupported"));
    }

    public function refund($reference_id, $transaction_id, $amount, $notes = null)
    {
        $this->Input->setErrors($this->getCommonError("unsupported"));
    }

    private function serializeInvoices(array $invoices)
    {
        $str = '';
        foreach ($invoices as $i => $invoice) {
            $str .= ($i > 0 ? '|' : '') . $invoice['id'] . '=' . $invoice['amount'];
        }

        return $str;
    }

    private function unserializeInvoices($str)
    {
        $invoices = [];
        $temp = explode('|', $str);
        foreach ($temp as $pair) {
            $pairs = explode('=', $pair, 2);
            if (count($pairs) != 2) {
                continue;
            }
            $invoices[] = ['id' => $pairs[0], 'amount' => $pairs[1]];
        }

        return $invoices;
    }

    private function coingateEnvironment()
    {

        if ($this->meta['coingate_environment'] == 'sandbox') {
            $test_mode = 'sandbox';
        } else {
            $test_mode = 'live';
        }

        return $test_mode;
    }

    private function coingateCallback($id)
    {

        Loader::load(dirname(__FILE__) . DS . 'coingate-php' . DS . 'init.php');

        $test_mode = $this->coingateEnvironment();

        $order = \CoinGate\Merchant\Order::find($id, array(), array(
            'environment' => $test_mode,
            'app_id'      => $this->meta['app_id'],
            'api_key'     => $this->meta['api_key'],
            'api_secret'  => $this->meta['api_secret'],
            'user_agent'  => 'CoinGate - Blesta v' .BLESTA_VERSION . ' Extension v' . $this->getVersion(),
        ));

        return $order;
    }

    public function statusChecking($id) {

        $status = 'error';

        $cgOrder = $this->coingateCallback($id);

        if (isset($cgOrder)) {
            switch ($cgOrder->status) {
                case 'pending':
                    $status = 'pending';
                    break;
                case 'confirming':
                    $status = 'pending';
                    break;
                case 'paid':
                    $status = 'approved';
                    break;
                case 'invalid':
                    $status = 'declined';
                    break;
                case 'canceled':
                    $status = 'declined';
                    break;
                case 'expired':
                    $status = 'declined';
                    break;
                case 'refunded':
                    $status = 'refunded';
                    break;
                default:
                    $status = 'pending';
            }
        }

        return $status;

    }

}
