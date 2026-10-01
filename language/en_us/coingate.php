<?php
$lang['Coingate.name'] = 'CoinGate.com';
$lang['Coingate.description'] = 'Pay with Bitcoin or Altcoins via CoinGate.com';
$lang['Coingate.auth_token'] = 'Auth Token';
$lang['Coingate.warning_v2_auth_token'] = 'CoinGate no longer supports the API v1 credentials (APP ID, API Key and API Secret) previously used by this gateway. Generate a new API v2 Auth Token in your CoinGate account and enter it below. This gateway will not work until an Auth Token is added.';
$lang['Coingate.coingate_environment'] = 'CoinGate Environment';
$lang['Coingate.environment.sandbox'] = 'Sandbox';
$lang['Coingate.environment.live'] = 'Live';
$lang['Coingate.receive_currency'] = 'Payout Currency';
$lang['Coingate.receive_currency_note'] = 'Currency you want to receive when making withdrawal at CoinGate.
										   Please note that if you choose EUR or USD you will be asked to verify
										   your business before making a withdrawal at CoinGate.';
$lang['Coingate.environment_note'] = 'Enable "sandbox" to test on sandbox.coingate.com. Please note, that for sandbox mode you must generate a separate Auth Token on sandbox.coingate.com. Auth Tokens generated on coingate.com will not work for sandbox mode.';
$lang['Coingate.buildprocess.submit'] = 'CoinGate.com';
$lang['Coingate.receive_currency.usd'] = 'US Dollars $';
$lang['Coingate.receive_currency.btc'] = 'Bitcoin ฿';
$lang['Coingate.receive_currency.eur'] = 'Euros €';
// Error
$lang['Coingate.!error.auth_token.empty'] = 'Auth Token can not be empty.';
$lang['Coingate.!error.auth_token.valid'] = 'The Auth Token is not valid for the selected CoinGate Environment.';
$lang['Coingate.!error.payment.invalid'] = 'The transaction is invalid and could not be processed.';
$lang['Coingate.!error.payment.canceled'] = 'The transaction is canceled and could not be processed.';
$lang['Coingate.!error.payment.expired'] = 'The transaction has expired and could not be processed.';
$lang['Coingate.!error.failed.response'] = 'The transaction could not be processed.';
