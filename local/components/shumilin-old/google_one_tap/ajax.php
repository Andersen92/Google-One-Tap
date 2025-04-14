<?php
define('STOP_STATISTICS', true);
define('NOT_CHECK_PERMISSIONS', true);

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use \Bitrix\Main\UserTable;
use \Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\JWK;
use \Bitrix\Main\Web\JWT;

require_once($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php');

global $USER, $APPLICATION;

if(!$USER->isAuthorized() && Loader::includeModule("socialservices")) {
    if (isset($_POST['token'])) {
        $idToken = $_POST["token"];

        // верификация токена и получение информации о пользователе
        $payload = '';
        $publicKeyDetails = [];

        $http = new HttpClient();
        $publicKeys = $http->get("https://www.googleapis.com/oauth2/v3/certs");
        $decodedPublicKeys = json_decode($publicKeys, true);
        if (!isset($decodedPublicKeys['keys']) || count($decodedPublicKeys['keys']) < 1)
        {
            return false;
        }

        $parsedPublicKeys = JWK::parseKeySet($decodedPublicKeys['keys']);
        foreach ($parsedPublicKeys as $keyId => $publicKey) {
            $details = openssl_pkey_get_details($publicKey);
            $publicKeyDetails[$keyId] = $details['key'];
        }
        if (is_array($publicKeyDetails)) {
            $payload = JWT::decode($idToken, $publicKeyDetails, ['RS256']);
        }
        $payload = (array)$payload;
        if($payload['email_verified'] && !empty($payload['sub']) && $payload['iss'] == 'https://accounts.google.com' /*&& CSocServAuthManager::CheckUniqueKey()*/) {
            $userID = 0;

            $dbUser = UserTable::getList(array(
                'select' => array('ID'),
                'filter' => array('LOGIN' => 'G_' . $payload['sub'], 'EMAIL' => $payload['email'])
            ));

            if ($arUser = $dbUser->fetch()){
                $userID = $arUser['ID'];
            }

            if(!$userID) {
                if (
                    COption::GetOptionString("main", "new_user_registration", "N") == "Y"
                    && COption::GetOptionString("socialservices", "allow_registration", "Y") == "Y"
                ) {
                    $userFields = [
                        'EXTERNAL_AUTH_ID' => 'socservices',
                        'LOGIN' => 'G_' . $payload['sub'],
                        'EMAIL' => $payload['email'],
                        'NAME'=> $payload['given_name'],
                        'LAST_NAME'=> $payload['family_name'],
                        'SITE_ID' => SITE_ID,
                        'LID' => SITE_ID
                    ];

                    $defGroup = Option::get('main', 'new_user_registration_def_group', '');
                    if($defGroup <> '') {
                        $userFields['GROUP_ID'] = explode(',', $defGroup);
                    }

                    $userID = $USER->Add($userFields);
                }
            }

            if($userID) {
                $result = $USER->Authorize($userID);
                if($result) {

                }
            }
        }
    }
}