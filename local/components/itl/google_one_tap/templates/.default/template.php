<?if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

/**
 * @global CMain $APPLICATION
 * @var array $arParams
 * @var array $arResult
 * @var CatalogProductsViewedComponent $component
 * @var CBitrixComponentTemplate $this
 * @var string $templateNames
 * @var string $componentPath
 * @var string $templateFolder
 * @global CUser $USER
 */
?>

<?php if(!$USER->isAuthorized()):?>
    <?php if($arParams['AJAX_MODE'] == 'Y'):?>
        <div id="g_id_onload"
            data-client_id="<?=$arResult['GOOGLE_CLIENT_ID']?>"
            data-callback="googleLoginEndpoint"
            data-context="signin"
            data-cancel_on_tap_outside="false"
            data-close_on_tap_outside="false"
            data-moment_callback="continueWithNextIdp"
        >
        </div>
    <?php else:?>
        <div id="g_id_onload"
            data-client_id="<?=$arResult['GOOGLE_CLIENT_ID']?>"
            data-login_uri="<?=$arResult['LOGIN_URI']?>"
            data-state="<?=$arResult['STATE']?>"
            data-context="signin"
            data-cancel_on_tap_outside="false"
            data-close_on_tap_outside="false"
            data-moment_callback="continueWithNextIdp"
        >
        </div>
    <?php endif;?>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <script>
        <?php if($arParams['AJAX_MODE'] == 'Y'):?>
            // callback function that will be called when the user is successfully logged-in with Google
            function googleLoginEndpoint(googleUser) {
                // get user information from Google
                console.log(googleUser);
                // send google credentials in the AJAX request
                var idToken = googleUser.credential;
                BX.ajax.runComponentAction('itl:google_one_tap', 'authorize', {
                    mode: 'class',
                    data: {
                        idToken: idToken
                    },
                }).then(function (response) {
                    console.log(response);
                    if(response.status == 'success') {
                        location.reload();
                    }
                }, function (response) {
                    console.log(response);
                });
            }

        <?php endif;?>

        function continueWithNextIdp(notification) {
            if (notification.isNotDisplayed()) {
                console.log('NotDisplayedReason = ', notification.getNotDisplayedReason());
            } else if (notification.isSkippedMoment()) {
                console.log('getSkippedReason = ', notification.getSkippedReason());
            } else if (notification.isDismissedMoment()) {
                console.log('DismissedReason = ', notification.getDismissedReason());
            }
        }
    </script>
<?php endif;?>