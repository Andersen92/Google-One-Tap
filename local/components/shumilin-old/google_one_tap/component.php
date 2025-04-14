<?php
if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) die();

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use \Bitrix\Main\UserTable;
use \Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\JWK;
use \Bitrix\Main\Web\JWT;

require_once($_SERVER['DOCUMENT_ROOT']. "/bitrix/vendor/autoload.php");

/** @var CBitrixComponent $this */
/** @var array $arParams */
/** @var array $arResult */
/** @var string $componentPath */
/** @var string $componentName */
/** @var string $componentTemplate */
/** @global CDatabase $DB */
/** @global CUser $USER */
/** @global CMain $APPLICATION */

if(!$USER->isAuthorized() && Loader::includeModule("socialservices")) {
    $arResult['GOOGLE_CLIENT_ID'] = \Bitrix\Main\Config\Option::get("socialservices", "google_appid");
    $arResult['GOOGLE_CLIENT_SECRET'] = \Bitrix\Main\Config\Option::get("socialservices", "google_appsecret");

    if($arParams['AJAX_MODE'] !== 'Y' && !empty($arResult['GOOGLE_CLIENT_ID']) && !empty($arResult['GOOGLE_CLIENT_SECRET'])) {
        if (isset($_POST['credential'])) {
            $idToken = $_POST["credential"];

            // верификация токена и получение информации о пользователе
            $payload = '';
            $publicKeyDetails = [];

            $http = new HttpClient();
            $publicKeys = $http->get("https://www.googleapis.com/oauth2/v3/certs");
            $decodedPublicKeys = json_decode($publicKeys, true);
            if (!isset($decodedPublicKeys['keys']) || count($decodedPublicKeys['keys']) < 1) {
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
                        LocalRedirect($APPLICATION->GetCurPage());
                    }
                }
            }
        }
    }

    $arResult['LOGIN_URI'] = \CHTTP::URN2URI($APPLICATION->GetCurPage(false));
    $arResult['STATE'] = 'provider=' . \CSocServGoogleOAuth::ID . '&site_id=' . SITE_ID . '&backurl=' . urlencode($GLOBALS["APPLICATION"]->GetCurPageParam('', array("logout", "auth_service_error", "auth_service_id", "backurl"))) . '&mode=popup' .'&check_key=' . \CSocServAuthManager::getUniqueKey() . '&response_type=code&redirect_url=' . urlencode(\CHTTP::URN2URI($APPLICATION->GetCurPage(false)));
}

$this->IncludeComponentTemplate();
?>