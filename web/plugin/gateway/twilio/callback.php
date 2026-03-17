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

// set gateway name and log marker
define('_CALLBACK_GATEWAY_NAME_', 'twilio');
define('_CALLBACK_GATEWAY_LOG_MARKER_', _CALLBACK_GATEWAY_NAME_ . ' callback');

error_reporting(0);

// load callback init
if (!(isset($PLAYSMS_INIT_SKIP) && $PLAYSMS_INIT_SKIP === true) && is_file('../common/callback_init.php')) {
	include '../common/callback_init.php';
}

$remote_smslog_id = isset($_REQUEST['SmsSid'])
	? preg_replace('/[^a-zA-Z0-9]/', '', (string) $_REQUEST['SmsSid']) // Replace all non-alphanumeric characters with an empty string
	: '';
$status = isset($_REQUEST['SmsStatus']) ? trim((string) $_REQUEST['SmsStatus']) : '';

// delivery receipt
if (
	$remote_smslog_id &&
	$status && 
	$status !== 'received'
) {
	$db_query = "SELECT local_smslog_id FROM " . _DB_PREF_ . "_gatewayTwilio WHERE remote_smslog_id=?";
	$db_result = dba_query($db_query, [$remote_smslog_id]);
	$db_row = $db_result
		? dba_fetch_array($db_result)
		: null;
	$smslog_id = $db_row && isset($db_row['local_smslog_id'])
		? (int) $db_row['local_smslog_id']
		: 0;
	if ($smslog_id) {
		$sms_data = sendsms_get_sms($smslog_id);
		$data = (is_array($sms_data) && isset($sms_data[0]))
			? $sms_data[0]
			: null;
		$uid = $data && isset($data['uid'])
			? (int) $data['uid']
			: 0;
		$p_status = 2;
		switch ($status) {
			case "delivered":
				$p_status = 3;
				break;
			default:
				$p_status = 2;
				break;
		}
		_log("dlr uid:" . $uid . " smslog_id:" . $smslog_id . " message_id:" . $remote_smslog_id . " status:" . $status, 2, _CALLBACK_GATEWAY_LOG_MARKER_);
		dlr($smslog_id, $uid, $p_status);
		ob_end_clean();
	}
	exit();
}

// incoming message
$sms_datetime = core_get_datetime();
$sms_sender = isset($_REQUEST['From'])
	? trim((string) $_REQUEST['From'])
	: '';
$message = isset($_REQUEST['Body'])
	? htmlspecialchars_decode(urldecode(trim((string) $_REQUEST['Body'])))
	: '';
$sms_receiver = isset($_REQUEST['To'])
	? trim((string) $_REQUEST['To'])
	: '';
$smsc = isset($_REQUEST['smsc'])
	? trim((string) $_REQUEST['smsc'])
	: '';

// ref: https://www.twilio.com/docs/api/rest/sms#list
if (
	$remote_smslog_id &&
	$message !== '' &&
	$status === 'received'
) {
	_log("incoming smsc:" . $smsc . " message_id:" . $remote_smslog_id . " s:" . $sms_sender . " d:" . $sms_receiver, 2, _CALLBACK_GATEWAY_LOG_MARKER_);
	$sms_sender = addslashes($sms_sender);
	$message = addslashes($message);
	recvsms($sms_datetime, $sms_sender, $message, $sms_receiver, $smsc);
}

