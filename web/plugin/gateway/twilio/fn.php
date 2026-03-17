<?php

/**
 * This file is part of playSMS.
 *
 * playSMS is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * playSMS is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with playSMS. If not, see <http://www.gnu.org/licenses/>.
 */
defined('_SECURE_') or die('Forbidden');

/**
 * This function hooks sendsms() and called by daemon sendsmsd
 *
 * @param string $smsc Selected SMSC
 * @param string $sms_sender SMS sender ID
 * @param string $sms_footer SMS message footer
 * @param string $sms_to Mobile phone number
 * @param string $sms_msg SMS message
 * @param int $uid User ID
 * @param int $gpid Group phonebook ID
 * @param int $smslog_id SMS Log ID
 * @param string $sms_type Type of SMS
 * @param int $unicode Indicate that the SMS message is in unicode
 * @return bool true if delivery successful
 */
function twilio_hook_sendsms(
	$smsc,
	$sms_sender,
	$sms_footer,
	$sms_to,
	$sms_msg,
	$uid = 0,
	$gpid = 0,
	$smslog_id = 0,
	$sms_type = 'text',
	$unicode = 0
) {
	global $plugin_config;

	// override $plugin_config by $plugin_config from selected SMSC
	$plugin_config = gateway_apply_smsc_config($smsc, $plugin_config);

	// re-filter, sanitize, modify some vars if needed
	$module_sender = isset($plugin_config['twilio']['module_sender']) && core_sanitize_sender($plugin_config['twilio']['module_sender'])
		? core_sanitize_sender($plugin_config['twilio']['module_sender'])
		: '';
	$sms_sender = $module_sender ?: core_sanitize_sender($sms_sender);
	$sms_to = core_sanitize_mobile($sms_to);
	$sms_footer = core_sanitize_footer($sms_footer);
	$sms_msg = stripslashes($sms_msg . $sms_footer);

	// log it
	_log("enter smsc:" . $smsc . " smslog_id:" . $smslog_id . " uid:" . $uid . " from:" . $sms_sender . " to:" . $sms_to, 3, "twilio_hook_sendsms");

	$ok = false;
	$p_status = 2;

	if ($sms_sender && $sms_to && $sms_msg) {
		$url = $plugin_config['twilio']['url'] . '/2010-04-01/Accounts/' . $plugin_config['twilio']['account_sid'] . '/Messages.json';
		$data = [
			'To' => $sms_to,
			'From' => $sms_sender,
			'Body' => $sms_msg
		];
		$callback_url = trim($plugin_config['twilio']['callback_url'] ?? '');
		if ($callback_url) {
			$data['StatusCallback'] = $callback_url;
		}
		if (function_exists('curl_init')) {
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_USERPWD, $plugin_config['twilio']['account_sid'] . ':' . $plugin_config['twilio']['auth_token']);
			curl_setopt($ch, CURLOPT_TIMEOUT, 30);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			$returns = curl_exec($ch);
			curl_close($ch);
			_log("sendsms url:[" . $url . "] callback:[" . $callback_url . "] smsc:[" . $smsc . "]", 3, "twilio_hook_sendsms");
			$resp = json_decode($returns);
			if (is_object($resp) && isset($resp->status)) {
				$c_status = is_numeric($resp->status) && $resp->status >= 400
					? "failed"
					: $resp->status;
				$c_message_id = $resp->sid ?? '';
				$c_error_text = $c_status . '|' . ($resp->code ?? '');
				_log("sent smslog_id:" . $smslog_id . " message_id:" . $c_message_id . " status:" . $c_status . " error:" . $c_error_text . " smsc:[" . $smsc . "]", 2, "twilio_hook_sendsms");
				$id = dba_add(_DB_PREF_ . '_gatewayTwilio', [
					'local_smslog_id' => (int) $smslog_id,
					'remote_smslog_id' => $c_message_id,
					'status' => $c_status,
					'error_text' => $c_error_text
				]);
				if ($id && ($c_status === 'queued' || $c_status === 'sent' || $c_status === 'delivered')) {
					$ok = true;
					$p_status = 0;
				} else {
					$p_status = 2;
				}
				dlr($smslog_id, $uid, $p_status);
			} else {
				$response_debug = is_string($returns)
					? str_replace(["\n", "\r"], " ", $returns)
					: json_encode($returns);
				_log("failed smslog_id:" . $smslog_id . " resp:" . $response_debug . " smsc:[" . $smsc . "]", 2, "twilio_hook_sendsms");
			}
		} else {
			_log("fail to sendsms due to missing PHP curl functions", 3, "twilio_hook_sendsms");
		}
	}

	if (!$ok) {
		dlr($smslog_id, $uid, $p_status);
	}

	_log("sendsms end", 3, "twilio_hook_sendsms");

	return $ok;
}
