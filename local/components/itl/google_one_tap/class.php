<?php

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Errorable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use \Bitrix\Main\UserTable;
use \Bitrix\Main\Web\HttpClient;
use \Bitrix\Main\Web\Json;
use Bitrix\Main\Web\JWK;
use \Bitrix\Main\Web\JWT;

if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) die();

if (!Loader::includeModule('socialnetwork'))
{
    ShowError(Loc::getMessage('GOT_MODULE_NOT_INSTALL'));
    return false;
}

class GoogleOneTapComponent extends \CBitrixComponent implements  Controllerable, Errorable
{
    /** @var ErrorCollection */
    protected $errorCollection;

    private const FEDERATED_SIGNON_CERT_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const OAUTH2_ISSUER_HTTPS = 'https://accounts.google.com';
    const STATUS_ERROR = 'error';

    public function __construct($component = null)
    {
        parent::__construct($component);

        $this->errorCollection = new ErrorCollection();
    }

    public function configureActions()
    {
        return [
            'authorize' => [
                '-prefilters' => [
                    ActionFilter\Authentication::class,
                ],
            ],
        ];
    }

    /**
     * Getting array of errors.
     *
     * @return Error[]
     */
    public function getErrors()
    {
        return $this->errorCollection->toArray();
    }

    /**
     * Getting once error with the necessary code.
     *
     * @param string $code Code of error.
     * @return Error
     */
    public function getErrorByCode($code)
    {
        return $this->errorCollection->getErrorByCode($code);
    }

    /**
     * Show all errors from errorCollection
     */
    protected function showErrors(): void
    {
        $errors = [];

        foreach ($this->getErrors() as $error) {
            ShowError($error->getMessage());
            $errors[] = array(
                'message' => $error->getMessage(),
                'code' => $error->getCode(),
            );
        }

        if ($this->arParams['AJAX_MODE'] === 'Y') {
            $this->sendJsonResponse(array(
                'status' => self::STATUS_ERROR,
                'data' => null,
                'errors' => $errors,
            ));
        }
    }

    /**
     * Preparing component parameters
     * @param $arParams
     * @return array
     */
    public function onPrepareComponentParams($arParams)
    {
        $arParams['AJAX_MODE'] = (isset($arParams['AJAX_MODE']) && $arParams['AJAX_MODE'] == 'Y') ? 'Y' : 'N';

        return $arParams;
    }

    public function executeComponent()
    {
        global $USER, $APPLICATION;

        $this->arResult = $this->prepareData();

        if(!$USER->isAuthorized()) {
            if (isset($_POST['credential'])) {
                $idToken = $_POST["credential"];
                if (empty($idToken)) {
                    ShowError(Loc::getMessage('GOT_EMPTY_TOKEN'));
                    return false;
                }

                $result = $this->authorize($idToken);
                if($result) {
                    LocalRedirect($APPLICATION->GetCurPage());
                } else if ($this->errorCollection->getValues()) {
                    $this->showErrors();
                }
            }
        }

        $this->includeComponentTemplate();
    }

    private function prepareData()
    {
        global $APPLICATION;

        $arResult = [];

        $arResult['GOOGLE_CLIENT_ID'] = Option::get("socialservices", "google_appid");
        $arResult['GOOGLE_CLIENT_SECRET'] = Option::get("socialservices", "google_appsecret");
        $arResult['LOGIN_URI'] = \CHTTP::URN2URI($APPLICATION->GetCurPage(false));
        $arResult['STATE'] = 'provider=' . \CSocServGoogleOAuth::ID . '&site_id=' . SITE_ID . '&backurl=' . urlencode($GLOBALS["APPLICATION"]->GetCurPageParam('', array("logout", "auth_service_error", "auth_service_id", "backurl"))) . '&mode=popup' . '&check_key=' . \CSocServAuthManager::getUniqueKey() . '&response_type=code&redirect_url=' . urlencode(\CHTTP::URN2URI($APPLICATION->GetCurPage(false)));

        return $arResult;
    }

    /**
     * Authorize user from AJAX mode
     *
     * @param string $idToken
     */
    function authorizeAction($idToken)
    {
        if (empty($idToken)) {
            ShowError(Loc::getMessage('GOT_EMPTY_TOKEN'));
            return false;
        }

        $result = $this->authorize($idToken);
        echo Json::encode($result);
    }

    /**
     * Authorize user
     *
     * @param string $idToken
     * @return bool
     */
    function authorize($idToken)
    {
        global $USER;
        $payload = $this->verifyIdToken($idToken);

        if(!$payload['email_verified']) {
            $this->errorCollection->add([new Error(Loc::getMessage('GOT_PAYLOAD_EMAIL_NOT_VERIFIED'))]);
            return false;
        }

        if(empty($payload['sub'])) {
            $this->errorCollection->add([new Error(Loc::getMessage('GOT_PAYLOAD_MISSING_ID'))]);
            return false;
        }

        if($payload['iss'] !== self::OAUTH2_ISSUER_HTTPS) {
            $this->errorCollection->add([new Error(Loc::getMessage('GOT_PAYLOAD_WRONG_AUTH_URL'))]);
            return false;
        }

        $userID = 0;
        $dbUser = UserTable::getList(array(
            'select' => array('ID'),
            'filter' => array('LOGIN' => 'G_' . $payload['sub'], 'EMAIL' => $payload['email'])
        ));

        if ($arUser = $dbUser->fetch()) {
            $userID = $arUser['ID'];
        }

        if ($userID) {
            $result = $USER->Authorize($userID);
            return $result;
        } else {
            $userID = $this->createUser($payload);
            if ($userID) {
                $result = $USER->Authorize($userID);
                return $result;
            } else {
                $this->errorCollection->add([new Error(Loc::getMessage('GOT_ERROR_CREATE_USER'))]);
                return false;
            }
        }
    }

    /**
     * Verifies an id token and returns the authenticated apiLoginTicket.
     * Throws an exception if the id token is not valid.
     *
     * @param string $idToken the ID token in JWT format
     * @return array|false the token payload, if successful
     */
    private function verifyIdToken($idToken) {
        $payload = [];
        $publicKeyDetails = [];

        $http = new HttpClient();
        $publicKeys = $http->get(self::FEDERATED_SIGNON_CERT_URL);
        $decodedPublicKeys = json_decode($publicKeys, true);
        if (!isset($decodedPublicKeys['keys']) || count($decodedPublicKeys['keys']) < 1) {
            $this->errorCollection->add([new Error(Loc::getMessage('GOT_MISSING_PUBLIC_KEYS'))]);
            return false;
        }

        $parsedPublicKeys = JWK::parseKeySet($decodedPublicKeys['keys']);
        if(!empty($parsedPublicKeys)) {
            foreach ($parsedPublicKeys as $keyId => $publicKey) {
                $details = openssl_pkey_get_details($publicKey);
                $publicKeyDetails[$keyId] = $details['key'];
            }
        } else {
            $this->errorCollection->add([new Error(Loc::getMessage('GOT_ERROR_PARSING_PUBLIC_KEYS'))]);
            return false;
        }

        if (is_array($publicKeyDetails)) {
            $payload = JWT::decode($idToken, $publicKeyDetails, ['RS256']);
        }
        $payload = (array)$payload;

        return $payload;
    }

    /**
     * Create user from payload info
     *
     * @param array $payload
     * @return int|false
     */
    private function createUser($payload)
    {
        global $USER;

        $userID = 0;

        if (COption::GetOptionString("main", "new_user_registration", "N") !== "Y") {
            $this->errorCollection->add([new Error(Loc::getMessage('GOT_NOT_PERMISSION_IN_MAIN_MODULE'))]);
            return false;
        }

        if (COption::GetOptionString("socialservices", "allow_registration", "N") !== "Y") {
            $this->errorCollection->add([new Error(Loc::getMessage('GOT_NOT_PERMISSION_IN_SOCIALSERV_MODULE'))]);
            return false;
        }

        $userFields = [
            'EXTERNAL_AUTH_ID' => 'socservices',
            'LOGIN' => 'G_' . $payload['sub'],
            'EMAIL' => $payload['email'],
            'NAME' => $payload['given_name'],
            'LAST_NAME' => $payload['family_name'],
            'SITE_ID' => SITE_ID,
            'LID' => SITE_ID
        ];

        $defGroup = Option::get('main', 'new_user_registration_def_group', '');
        if ($defGroup <> '') {
            $userFields['GROUP_ID'] = explode(',', $defGroup);
        }
        $userID = $USER->Add($userFields);

        return $userID;
    }
}