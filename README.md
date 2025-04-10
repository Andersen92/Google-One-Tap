# Google One Tap
Компонент для авторизации на сайте с CMS Битрикс с помощью Google One Tap

Данный компонент позволяет авторизовываться на сайте с CMS Битрикс с помощью Google One Tap с последующим созданием пользователя на сайте

1 Создание приложения в Google:
1.1. Перейдите по ссылке https://console.developers.google.com/
1.2. Нажмите на (Credentials) в левом сайдбаре. На странице жмём Create Credentials и выбираем OAuth client ID. 
1.3 Заполняем информацию в полях:

Application type: Web application
Name: Произвольное название (например Google One Tap)

Authorized JavaScript origins
URLs: Домен сайта (http://bitrix.ru)

Authorized redirect URLs
URLs: Ссылка с настроек модуля (http://bitrix.ru/bitrix/tools/oauth/google.php)

Жмём CREATE

1.4. Скопировать Client ID и вставить в настройках модуля Социальные сервисы в секции Настройки Google в поле Идентификатор (Client ID): 

2. Подключение компонента:
<code>
<?php 
$APPLICATION->IncludeComponent(
	"shumilin:google_one_tap",
	".default",
	array(
		"AJAX_MODE" => "Y"
	)
);?>
</code>


