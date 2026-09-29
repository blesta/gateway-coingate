<?php
/**
 * Coingate Payment Gateway
 *
 * Allows users to pay with Bitcoins and Altcoins
 *
 * @package blesta
 * @subpackage blesta.components.gateways.nonmerchant.coingate
 * @author CoinGate
 * @copyright Copyright (c) 2018, Phillips Data, Inc. Copyright (c) 2018, CoinGate
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @license http://github.com/blesta/coingate/blob/master/LICENSE
 * @link http://www.blesta.com/ Blesta
 * @link https://coingate.com Coingate
 */
class Coingate extends NonmerchantGateway
{
    private $meta;

    /**
     * @var bool Whether the last notification failed for a reason CoinGate should retry
     */
    private $retryable = false;
    public function __construct()
    {
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        Loader::loadComponents($this, ['Input']);

        Loader::loadModels($this, ['Clients']);

        Language::loadLang('coingate', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    /**
     * {@inheritdoc}
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    /**
     * {@inheritdoc}
     */
    public function getSettings(array $meta = null)
    {
        $this->view = $this->makeView('settings', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS));

        Loader::loadHelpers($this, ['Form', 'Html']);

        $receiveCurrency = [
            'BTC' => Language::_('Coingate.receive_currency.btc', true),
            'EUR' => Language::_('Coingate.receive_currency.eur', true),
            'USD' => Language::_('Coingate.receive_currency.usd', true),
        ];

        $coingateEnvironment = [
            'sandbox' => Language::_('Coingate.environment.sandbox', true),
            'live' => Language::_('Coingate.environment.live', true),
        ];

        $this->view->set('meta', $meta);
        $this->view->set('receive_currency', $receiveCurrency);
        $this->view->set('coingate_environment', $coingateEnvironment);

        return $this->view->fetch();
    }

    /**
     * {@inheritdoc}
     */
    public function editSettings(array $meta)
    {
        $rules = [
            'app_id'     => [
                'empty' => [
                    'rule'    => 'isEmpty',
                    'negate'  => true,
                    'message' => Language::_('Coingate.!error.app_id.empty', true),
                ],
            ],
            'api_key'    => [
                'empty' => [
                    'rule'    => 'isEmpty',
                    'negate'  => true,
                    'message' => Language::_('Coingate.!error.api_key.empty', true),
                ],
            ],
            'api_secret' => [
                'empty' => [
                    'rule'    => 'isEmpty',
                    'negate'  => true,
                    'message' => Language::_('Coingate.!error.api_secret.empty', true),
                ],
            ],
        ];

        $this->Input->setRules($rules);

        $this->Input->validates($meta);

        return $meta;
    }

    /**
     * {@inheritdoc}
     */
    public function encryptableFields()
    {
        return ['app_id', 'api_key', 'api_secret'];
    }

    /**
     * {@inheritdoc}
     */
    public function setMeta(array $meta = null)
    {
        $this->meta = $meta;
    }

    /**
     * {@inheritdoc}
     */
    public function buildProcess(array $contactInfo, $amount, array $invoiceAmounts = null, array $options = null)
    {
        Loader::load(dirname(__FILE__) . DS . 'init.php');

        $clientId = (isset($contactInfo['client_id']) ? $contactInfo['client_id'] : null);

        if (isset($invoiceAmounts) && is_array($invoiceAmounts)) {
            $invoices = $this->serializeInvoices($invoiceAmounts);
        }

        $record = new Record();
        $companyName = $record->select('name')->from('companies')->where('id', '=', 1)->fetch();

        $orderId = $clientId . '@' . (!empty($invoices) ? $invoices : time());
        $token = $this->callbackToken($orderId);

        $callbackURL = Configure::get('Blesta.gw_callback_url')
            . Configure::get('Blesta.company_id') . '/coingate/?client_id='
            . (isset($contactInfo['client_id']) ? $contactInfo['client_id'] : null) . '&token=' . $token;

        $postParams = [
            'order_id'         => $orderId,
            'price'            => (isset($amount) ? $amount : null),
            'description'      => (isset($options['description']) ? $options['description'] : null),
            'title'            => $companyName->name . ' ' . (isset($options['description']) ? $options['description'] : null),
            'token'            => $token,
            'currency'         => (isset($this->currency) ? $this->currency : null),
            'receive_currency' => $this->meta['receive_currency'],
            'callback_url'     => $callbackURL,
            'cancel_url'       => (isset($options['return_url']) ? $options['return_url'] : null),
            'success_url'      => (isset($options['return_url']) ? $options['return_url'] : null),
        ];

        $order = \CoinGate\Merchant\Order::create($postParams, [], $this->apiAuthentication());

        if ($order && $order->payment_url) {
            header('Location: ' . $order->payment_url);
        } else {
            print_r($order);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function validate(array $get, array $post)
    {
        $this->log((isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null), serialize($post), 'output', true);

        $this->retryable = false;
        $response = $this->processNotification($get, $post);

        // A notification that could not be verified because CoinGate could not be reached must
        // not be acknowledged, or the payment it announces is lost. Ask CoinGate to send it again
        if ($response === null && $this->retryable) {
            $this->sendRetryResponse();
        }

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function success(array $get, array $post)
    {
        // CoinGate returns the customer to the success URL with a plain redirect that carries no
        // order data, so there is nothing to verify and nothing to report
        if (empty($post['id'])) {
            return null;
        }

        return $this->processNotification($get, $post);
    }

    /**
     * Verifies a payment notification against the CoinGate order it refers to and builds the
     * transaction data for it
     *
     * The request is trusted only to identify the CoinGate order. Every value that is recorded
     * (status, amount, currency, client and invoices) is read from the order as fetched from
     * CoinGate with the merchant's API credentials, so a caller can not have anything credited
     * that CoinGate did not actually receive
     *
     * @param array $get The GET parameters of the request
     * @param array $post The POST parameters of the request
     * @return array|null The transaction data as expected of NonmerchantGateway::validate(), or
     *  null if the notification could not be verified (errors are set)
     */
    private function processNotification(array $get, array $post)
    {
        $requestUri = (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null);

        // The CoinGate order ID is the only value taken from the request. Orders are identified
        // by integer, and the ID becomes part of the API path, so accept nothing else
        $cgOrderId = (isset($post['id']) && is_scalar($post['id']) ? (string) $post['id'] : '');
        if ($cgOrderId === '' || !ctype_digit($cgOrderId)) {
            return $this->fail($requestUri, 'order', 'missing');
        }

        // Fetch the order from CoinGate. This is the source of truth for everything below
        $order = $this->getOrder($cgOrderId);
        if ($order === false) {
            return $this->fail($requestUri, 'order', 'not_found');
        }
        if ($order === null) {
            $this->retryable = true;
            return $this->fail($requestUri, 'order', 'unavailable');
        }

        // The order must identify itself as the one that was asked for. The recorded
        // transaction ID comes from here, never from the request
        $transactionId = (isset($order['id']) && is_scalar($order['id']) ? (string) $order['id'] : '');
        if ($transactionId !== $cgOrderId) {
            return $this->fail($requestUri, 'order', 'malformed');
        }

        // The bundled library targets CoinGate's v1 API, which reports the order's price as
        // "price" and "currency". Accept the v2 names as well in case the API is upgraded
        $orderId = (isset($order['order_id']) && is_scalar($order['order_id']) ? (string) $order['order_id'] : '');
        $amount = (isset($order['price'])
            ? $order['price']
            : (isset($order['price_amount']) ? $order['price_amount'] : null)
        );
        $currency = (isset($order['currency'])
            ? $order['currency']
            : (isset($order['price_currency']) ? $order['price_currency'] : null)
        );
        $cgStatus = (isset($order['status']) ? $order['status'] : null);

        if ($orderId === '' || !is_numeric($amount) || !is_string($currency) || $currency === '' || empty($cgStatus)) {
            return $this->fail($requestUri, 'order', 'malformed');
        }

        // The callback token is bound to the verified merchant reference. It is an additional
        // check on top of the verified order, not the trust boundary
        if (!$this->validateToken((isset($get['token']) ? $get['token'] : null), $orderId)) {
            return $this->fail($requestUri, 'token', 'invalid');
        }

        // The merchant reference was set by buildProcess() as "client_id@invoice_id=amount|..."
        $dataParts = explode('@', $orderId, 2);
        $clientId = $dataParts[0];
        $invoices = (isset($dataParts[1]) && !is_numeric($dataParts[1]) ? $dataParts[1] : null);

        // The client must exist within the company handling this notification
        if (!ctype_digit($clientId) || !$this->Clients->get($clientId)) {
            return $this->fail($requestUri, 'client', 'invalid');
        }

        return [
            'client_id'      => $clientId,
            'amount'         => $amount,
            'currency'       => $currency,
            'status'         => $this->mapStatus($cgStatus),
            'reference_id'   => null,
            'transaction_id' => $transactionId,
            'invoices'       => $this->verifyInvoices(
                $this->unserializeInvoices($invoices),
                $clientId,
                $amount,
                $currency,
                $requestUri
            ),
        ];
    }

    /**
     * Records a failed notification and sets the matching error
     *
     * @param string $requestUri The URI of the notification request
     * @param string $field The error field
     * @param string $rule The error rule
     * @return null
     */
    private function fail($requestUri, $field, $rule)
    {
        $message = Language::_('Coingate.!error.' . $field . '.' . $rule, true);

        $this->log($requestUri, $message, 'output', false);
        $this->Input->setErrors([$field => [$rule => $message]]);

        return null;
    }

    /**
     * Confirms that the invoices a verified order refers to may be paid by it
     *
     * The reference was built by buildProcess() for this client, so a mismatch is not expected.
     * Should one occur, the payment is still recorded as a credit to the client but not applied,
     * so nothing is settled that the verified payment does not cover
     *
     * @param array $invoices A numerically indexed array of invoice info including:
     *  - id The ID of the invoice
     *  - amount The amount relating to the invoice
     * @param int $clientId The ID of the client the order was created for
     * @param float $amount The verified amount of the payment
     * @param string $currency The verified currency of the payment
     * @param string $requestUri The URI of the notification request
     * @return array The given invoices, or an empty array if they could not be verified
     */
    private function verifyInvoices(array $invoices, $clientId, $amount, $currency, $requestUri)
    {
        if (empty($invoices)) {
            return [];
        }

        if (!isset($this->Invoices)) {
            Loader::loadModels($this, ['Invoices']);
        }

        $total = 0;
        foreach ($invoices as $invoice) {
            $record = (ctype_digit((string) $invoice['id']) ? $this->Invoices->get($invoice['id']) : null);

            if (!$record
                || $record->client_id != $clientId
                || strcasecmp($record->currency, $currency) !== 0
                || !is_numeric($invoice['amount'])
                || $invoice['amount'] <= 0
            ) {
                $this->log($requestUri, Language::_('Coingate.!error.invoices.invalid', true), 'output', false);
                return [];
            }

            $total += $invoice['amount'];
        }

        // The allocations may not exceed what was paid. Partial payments are fine
        if (round($total, 8) > round($amount, 8)) {
            $this->log($requestUri, Language::_('Coingate.!error.invoices.invalid', true), 'output', false);
            return [];
        }

        return $invoices;
    }

    /**
     * Builds the callback token for the given merchant reference
     *
     * @param string $orderId The merchant reference given to CoinGate as the order_id
     * @return string The token
     */
    private function callbackToken($orderId)
    {
        return hash_hmac(
            'sha256',
            Configure::get('Blesta.company_id') . ':' . $orderId,
            (string) Configure::get('Blesta.system_key')
        );
    }

    /**
     * Validates the callback token given for a merchant reference
     *
     * @param mixed $token The token given in the callback request
     * @param string $orderId The merchant reference read from the verified CoinGate order
     * @return bool True if the token is valid for the reference, false otherwise
     */
    private function validateToken($token, $orderId)
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        if (hash_equals($this->callbackToken($orderId), $token)) {
            return true;
        }

        // Orders created by earlier versions of this gateway carry a token derived from the
        // reference alone. Those still awaiting their callback must remain payable. The token
        // is not what makes the notification trustworthy; the verified order is
        return hash_equals(md5($orderId), $token);
    }

    /**
     * Serializes an array of invoice info into a string
     *
     * @param array A numerically indexed array invoices info including:
     *  - id The ID of the invoice
     *  - amount The amount relating to the invoice
     * @return string A serialized string of invoice info in the format of key1=value1|key2=value2
     */
    private function serializeInvoices(array $invoices)
    {
        $str = '';
        foreach ($invoices as $i => $invoice) {
            $str .= ($i > 0 ? '|' : '') . $invoice['id'] . '=' . $invoice['amount'];
        }

        return $str;
    }

    /**
     * Unserializes a string of invoice info into an array
     *
     * @param string A serialized string of invoice info in the format of key1=value1|key2=value2
     * @return array A numerically indexed array invoices info including:
     *  - id The ID of the invoice
     *  - amount The amount relating to the invoice
     */
    private function unserializeInvoices($str)
    {
        $invoices = [];
        $temp = explode('|', (string) $str);
        foreach ($temp as $pair) {
            $pairs = explode('=', $pair, 2);
            if (count($pairs) != 2) {
                continue;
            }
            $invoices[] = ['id' => $pairs[0], 'amount' => $pairs[1]];
        }

        return $invoices;
    }

    /**
     * Determines the target CoinGate environment
     *
     * @return string The correct CoinGate environment
     */
    private function coingateEnvironment()
    {

        if ($this->meta['coingate_environment'] == 'sandbox') {
            $testMode = 'sandbox';
        } else {
            $testMode = 'live';
        }

        return $testMode;
    }

    /**
     * Builds the authentication options for the CoinGate API
     *
     * @return array The authentication options
     */
    private function apiAuthentication()
    {
        return [
            'environment' => $this->coingateEnvironment(),
            'app_id'      => $this->meta['app_id'],
            'api_key'     => $this->meta['api_key'],
            'api_secret'  => $this->meta['api_secret'],
            'user_agent'  => 'CoinGate - Blesta v' . BLESTA_VERSION . ' Extension v' . $this->getVersion(),
        ];
    }

    /**
     * Retrieves a CoinGate order for the given ID
     *
     * @param string $id The CoinGate order ID
     * @return array|false|null The order as returned by CoinGate, false if CoinGate reports no
     *  such order, or null if CoinGate could not be consulted (the lookup should be retried)
     */
    private function getOrder($id)
    {
        Loader::load(dirname(__FILE__) . DS . 'init.php');

        $url = '/orders/' . $id;

        try {
            $order = \CoinGate\Merchant\Order::find($id, [], $this->apiAuthentication());
        } catch (Throwable $e) {
            // Anything other than a definite "no such order" (which find() reports as false)
            // is a failure to consult CoinGate: network, credentials, or CoinGate itself
            $this->log($url, serialize($e->getMessage()), 'output', false);
            return null;
        }

        if ($order === false) {
            $this->log($url, serialize(false), 'output', false);
            return false;
        }

        $data = ($order ? $order->toHash() : null);
        if (!is_array($data)) {
            $this->log($url, serialize($data), 'output', false);
            return null;
        }

        $this->log($url, serialize($data), 'output', true);

        return $data;
    }

    /**
     * Answers the current notification request with a status that asks CoinGate to retry it
     */
    protected function sendRetryResponse()
    {
        if (!headers_sent()) {
            header('HTTP/1.1 503 Service Unavailable', true, 503);
            header('Retry-After: 300');
        }
    }

    /**
     * Maps a CoinGate order status to a transaction status
     *
     * @param string $cgStatus The status of the CoinGate order
     * @return string The transaction status
     */
    private function mapStatus($cgStatus)
    {
        switch ($cgStatus) {
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
                $this->Input->setErrors(
                    ['transaction' => ['response' => Language::_('Coingate.!error.payment.invalid', true)]]
                );
                break;
            case 'canceled':
                $status = 'declined';
                $this->Input->setErrors(
                    ['transaction' => ['response' => Language::_('Coingate.!error.payment.canceled', true)]]
                );
                break;
            case 'expired':
                $status = 'declined';
                $this->Input->setErrors(
                    ['transaction' => ['response' => Language::_('Coingate.!error.payment.expired', true)]]
                );
                break;
            case 'refunded':
                $status = 'refunded';
                break;
            default:
                $status = 'pending';
                $this->Input->setErrors(
                    ['transaction' => ['response' => Language::_('Coingate.!error.failed.response', true)]]
                );
        }

        return $status;
    }
}
