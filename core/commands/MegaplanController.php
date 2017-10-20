<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace app\commands;

use linslin\yii2\curl\Curl;
use Megaplan\SimpleClient\Client;
use yii\console\Controller;
use yii\console\Exception;
use yii\helpers\ArrayHelper;
use yii\helpers\Json;

/**
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class MegaplanController extends Controller
{
    public $url = 'https://espanarusa.bitrix24.ru/rest/49/yzmwq2ftvomdnpre/';

    public $employeesMap = [
        1000002 => 69,
        1000015 => 55,
        1000007 => 75,
        1000040 => 37,
        1000005 => 73,
        1000006 => 61,
        1000030 => 57,
        1000041 => 63,
        1000001 => 41,
        1000017 => 51,
        1000033 => 59,
    ];
    public $categoryMap = [
        ['Продажа наших услуг', 'Аренда недвижимости', 'Продажа рекламы', 'Базовая'],
        ['Получение комиссии'],
        ['Продажа икры'],
    ];
    public $statusMap = [
        'NEW' => ['Интерес', 'Запрос', 'Начало сделки'],
        'PREPARATION' => ['Отложено', 'Заказ', 'Коммерческое предложение', 'Договор', 'Комиссия подтверждена', 'Оформление заказа'],
        'PREPAYMENT_INVOICE' => ['Заказ подтверждён', 'Заказ подтверждён', 'Оплачено', 'Предоплата', 'Окончательные расчеты', 'Комиссия получена'],
        'EXECUTING' => ['Отгрузили', 'Услуги оказываются', 'Приемка', 'Устранение замечаний', 'Выполнение работ', 'Отправили счет-фактуру'],
        'FINAL_INVOICE' => ['Постоплата'],
        'WON' => ['Услуги оказаны', 'Закрыто', 'Постоплата', 'Устранение замечаний', 'Завершена'],
        'LOSE' => ['Отказ', 'Отвал'],
    ];

    private $log = [];
    private $bxLog = [];
    private $auth = [];

    public function actionIndex()
    {
        $i = 0;
        while (($list = $this->get('/BumsCrmApiV01/Contractor/list.api', ['Offset' => $i])) && count($list)) {
            $i += 500;
            foreach ($list as $client)
                $this->addClient($client);
            die;
        }
    }

    public function addClient($data)
    {
        $list = $this->get('/BumsTradeApiV01/Deal/list.api', ['FilterFields' => ['Contractor' => $data['Id']]]);
        $list = array_filter($list, function ($row) use ($data) {
            return $row['Contractor']['Id'] == $data['Id'];
        });

        if (empty($list) && empty($data['ParentCompany'])) $object = 'lead';
        elseif ($data['PersonType'] == 'human') $object = 'contact';
        else $object = 'company';

        $params = [
            "ORIGINATOR_ID" => "megaplan", // Идентификатор внешней информационной базы
            "ORIGIN_ID" => $data['Id'], // Внешний ключ
        ];

        $result = $this->exist('crm.' . $object . '.list', $params);
        if (!$result) {
            $data = ArrayHelper::merge($data, $this->get('/BumsCrmApiV01/Contractor/card.api', ['Id' => $data['Id'], 'RequestedFields' => array_map(function ($row) {
                return $row['Name'];
            }, $this->get('/BumsCrmApiV01/Contractor/listFields.api'))]));
            $params += [
                'TITLE' => $data['Name'], // Название. Обязательное поле.
                "POST" => "", // Должность
                "COMMENTS" => nl2br(trim($data['Description'])), // Комментарии
                "EMAIL" => [["VALUE" => $data['Email'], "VALUE_TYPE" => "WORK"]], // e-mail
                "PHONE" => array_map(function ($row) {
                    return ["VALUE" => $row, "VALUE_TYPE" => "WORK"];
                }, array_unique($data['Phones'])), // Телефон
                "ADDRESS" => preg_replace('#<br[^>]*>#', "\n", implode(",\n ", array_map(function ($row) {
                    return $row['Address'];
                }, array_diff_key($data['Locations'], ['', 'Адрес не указан'])))), // Адрес
                "ASSIGNED_BY_ID" => $this->employeesMap[$data['Responsibles'][0]['Id']] ?: 61, // Ответственный
                "SOURCE_ID" => 'OTHER', // Источник
                "BIRTHDATE" => $data['Birthday'] ?: $data['Category183CustomFieldDenRozhdeniya'], // Дата рождения
                "WEB" => $data['Site'], // веб-сайт
                "OPENED" => 'Y', // Доступен всем

                "STATUS_ID" => "1", // Идентификатор статуса лида

                "NAME" => $data['FirstName'], // Имя
                "SECOND_NAME" => $data['MiddleName'], // Отчество
                "LAST_NAME" => $data['LastName'], // Фамилия
                "COMPANY_TITLE" => $data['CompanyName'], // Название компании
                "TYPE_ID" => $data['Type']['Name'] == 'Партнеры' ? 'PARTNER' : 'CLIENT', // Тип контакта
                "HONORIFIC" => $data['Gender'] ? $data['Gender'] == 'male' ? 'HNR_RU_1' : 'HNR_RU_2' : '', // Обращение

                "COMPANY_TYPE" => $data['Type']['Name'] == 'Партнеры' ? 'PARTNER' : 'CUSTOMER', // Тип компании
            ];
            foreach (['Icq', 'Facebook', 'Jabber', 'Skype', 'Twitter'] as $im) if ($data[$im]) {
                $params['IM'][] = ["VALUE" => $data[$im], "VALUE_TYPE" => $im];
            }
            if ($data['ParentCompany']) {
                $company = $this->exist('crm.company.list', [
                    "ORIGINATOR_ID" => "megaplan", // Идентификатор внешней информационной базы
                    "ORIGIN_ID" => $data['ParentCompany']['Id'],// Внешний ключ
                ]);
                $params['COMPANY_ID'] = $company;
            }

            $result = $this->add('crm.' . $object . '.add', $params);
        }

        $this->addAttaches($result, $object, $data['Attaches']);

        foreach ($list as $lead) $this->addLead($result, $object, $lead);
    }

    public function addAttaches($itemId, $type, $data)
    {
        if ($data) {
//            print_r($itemId);
//            print_r($data);
//            die;
        }
    }

    public function addLead($clientId, $object, $data)
    {
        $params = [
            "ORIGINATOR_ID" => "megaplan", // Идентификатор внешней информационной базы
            "ORIGIN_ID" => $data['Id'], // Внешний ключ
        ];

        $result = $this->exist('crm.deal.list', $params);
        if (!$result) {
            $data = ArrayHelper::merge($data, $this->get('/BumsTradeApiV01/Deal/card.api', ['Id' => $data['Id'], 'RequestedFields' => array_map(function ($row) {
                return $row['Name'];
            }, $this->get('/BumsTradeApiV01/Deal/listFields.api'))]));

            $params += [
                'TITLE' => $data['Name'], // Название сделки. Обязательное поле.
                'TYPE_ID' => '', // Идентификатор типа сделки.
                "STAGE_ID" => key(array_filter($this->statusMap, function ($row) use ($data) {
                    return in_array(trim($data['Status']['Name']), $row);
                })), // Идентификатор этапа сделки
                "CATEGORY_ID" => key(array_filter($this->categoryMap, function ($row) use ($data) {
                    return in_array($data['Program']['Name'], $row);
                })), // Идентификатор направления сделки.
                "CURRENCY_ID" => $data['Cost']['CurrencyAbbreviation'], // Валюта сделки
                "OPPORTUNITY" => $data['Cost']['Value'], // Сумма в валюте сделки
                "COMPANY_ID" => $object == 'company' ? $clientId : '', // Идентификатор компании-контрагента сделки
                "CONTACT_ID" => $object == 'contact' ? $clientId : '', // Идентификатор контактного лица
                "BEGINDATE" => $data['TimeCreated'], // Дата открытия сделки
                "CLOSEDATE" => '', // Дата закрытия сделки
                "OPENED" => 'Y', // Сделка доступна для всех
                "CLOSED" => '', // Сделка закрыта
                "COMMENTS" => nl2br(trim($data['Description'])), // Комментарии
                "ASSIGNED_BY_ID" => $this->employeesMap[$data['Manager']['Id']] ?: 61, // Ответственный

                "ADDITIONAL_INFO" => "", // Дополнительная информация
            ];
            if (in_array($params['STAGE_ID'], ['WON', 'LOSE'])) {
                $params['CLOSEDATE'] = $data['TimeUpdated'];
            }
            $params['TYPE_ID'] = $params['CATEGORY_ID'];
            if ($params['CATEGORY_ID'] > 0) {
                var_dump($params['CATEGORY_ID']);
                die;
            }

            if (!$params['STAGE_ID'] || $params['CATEGORY_ID'] === false) {
                var_dump(1);
                print_r($clientId);
                print_r($data);
                print_r($params);
                die;
            }

            $result = $this->add('crm.deal.add', $params);
        }


        $this->addProducts($result, $data['Positions']);

        $this->addAttaches($result, 'deal', $data['Attaches']);

        return $result;
    }

    public function addProducts($dealId, $data)
    {
        if ($data) {
            $rows = [];
            foreach ($data as $row) {
                $rows[] = [
                    'PRODUCT_ID' => 0,
                    'OWNER_ID' => 0,
                    'OWNER_TYPE' => 'Q',
                    'PRODUCT_NAME' => $row['Name'],
                    'PRICE' => $row['DeclaredPrice']['Value'],
                    'QUANTITY' => $row['Count'],
                    'MEASURE_NAME' => $row['Offer']['Unit']['Name'],
                    'CUSTOMIZED' => 'Y',
                ];
            }

            $result = $this->add('crm.deal.productrows.set', [
                'id' => $dealId,
                'rows' => $rows,
            ]);
            return $result;
        }
        return false;
    }

    public function get($method, $params = null)
    {
        $cacheId = $method . serialize($params ?: []);
        $data = \Yii::$app->cache->get($cacheId);
        if (1 || $data === false) {
            $ll = count($this->log);
            if (($ll > 3000 && ($spend = time() - $this->log[$ll - 3000]) && $spend < ($max = 3600)) ||
                ($ll >= 3 && ($spend = microtime(true) - $this->log[$ll - 3]) && $spend < ($max = 1))
            ) {
                sleep($max - $spend);
                return $this->get($method, $params);
            }

            $this->log[] = microtime(true);
            $result = $this->auth()->get($method, $params);
            if (is_string($result)) $result = json_decode($result);
            if ($result->status->code != 'ok') {
                throw new Exception($result->status->message);
            }

            $data = current(json_decode(json_encode($result->data), 1));

            \Yii::$app->cache->set($cacheId, $data, 3600);
        }

        return $data;
    }

    public function auth()
    {
        return $this->auth ?: ($this->auth = (new Client('espanarusa.megaplan.ru'))->auth('info@espanarusa.com', 'pylypenko1984'));
    }

    public function exist($method, $data)
    {
        if (!$data['ORIGIN_ID']) return false;

        $result = $this->bx(str_replace(['.add', '.update'], '.list', $method), ['filter' => [
            'ORIGINATOR_ID' => $data['ORIGINATOR_ID'],
            'ORIGIN_ID' => $data['ORIGIN_ID']
        ]]);

        if ($result['total']) return $result['result'][0]['ID'];

        return false;
    }

    public function add($method, $data)
    {
        if (!$data['id']) $data = ['fields' => $data, 'params' => ['REGISTER_SONET_EVENT' => 'N']];
        return $this->bx($method, [], $data);
    }

    public function bx($method, $get = null, $post = null)
    {
        if ((count($this->bxLog) > 2 && ($spend = microtime(true) - $this->bxLog[count($this->bxLog) - 2]) && $spend < 1)) {
            sleep(1 - $spend);
            return $this->bx($method, $get, $post);
        }

        $url = $this->url;
        $url .= $method . '/';
        if ($get) $url .= '?' . http_build_query($get);

        $curl = new Curl();
        if ($post) {
            $curl->setPostParams($post);
            $result = $curl->post($this->url . '' . $method . '/', true);
        } else {
            $result = $curl->get($url);
        }

        $result = JSON::decode($result);

        if (!$result['result']) {
            print_r($url);
            print_r($post);
            print_r($result);
            die;
        }

        $this->bxLog = microtime(true);

        return $result['result'];
    }
}
