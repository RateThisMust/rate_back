<?php

namespace App\Service;

use App\Exceptions\AppException;
use App\Support\Connection;
use Carbon\Carbon;
use \PDO;

use \DatePeriod;
use \DateTime;
use \DateInterval;

use App\Support\Auth;

class DeliveryService
{
    private Connection $rateDB;

    public function __construct(
        private ExcelService $excelService,
        private EmailService $emailService,
        private FindService $fаindService
    ) {
        $this->rateDB = new Connection(config('connection.rate'));
    }

    private function calcStatus( $value ) {

        $statusKeys = [
            'ожидает' => 'Ожидает',
            '2' => 'Оплачено',
            '4' => 'Забран',
            'no-money' => 'Недостаточно средств',
        ];

        foreach ($statusKeys as $_key => $_value) {
            if ( preg_match('#'.$value.'#ui', $_key) ) {
                $value = $_value;
                break;
            }
        }

        $statuses = [
            'Ожидает' => 'Ожидает получения|plan',
            '2' => 'Ожидает получения|plan',
            '4' => 'Забран|succses',
            'Недостаточно средств' => 'Недостаточно средств|dunger',
        ];


        return @$statuses[ $value ];
    }



    /**
     * Список сгруппированных выкупов
     */
    public function list( array $params = [] ): array
    {

        $type = @$params['type'];

        // $task1 = 30570069;
        $task1 = @Auth::user()['task1'];
        if ( @$task1 ) $task1 = str_replace('#', '', $task1);

        $dbh = $this->rateDB->connection;

        $pvz_opt = [];

        if ( $type == 'im' ) {
            $headers = [
                ["text" => "Фото", "value" => 'image', 'sortable' => false],
                ["text" => "Артикул", "value" => 'art', 'sortable' => false],
                ["text" => "Цвет", "value" => 'color', 'sortable' => false],
                ["text" => "Размер", "value" => 'size', 'sortable' => false],
                ["text" => "ФИО", "value" => 'fio', 'sortable' => false],
                ["text" => "ПВЗ", "value" => 'pvz', 'sortable' => false],
                ["text" => "Статус", "value" => 'status', 'sortable' => false],
                ["text" => "Код", "value" => 'code', 'sortable' => false],
            ];

            $cache = [];


            $sql = "
                SELECT
                    '' AS `image`,
                    cl.article AS art,
                    '' AS color,
                    cl.size,
                    CONCAT(cl.name, ' ',cl.surname) AS fio,
                    cl.punkt_vidachi AS pvz,
                    cl.`status`,
                    cl.code
                FROM client cl WHERE 1
                AND cl.task1 = ?
                AND cl.mp = 'wb'
                AND ( cl.kto_zabirat != 'RATE-THIS' OR cl.kto_zabirat IS NULL)
                AND cl.punkt_vidachi IS NOT NULL
            ";

            $stmt = $dbh->prepare( $sql );
            $stmt->execute( [$task1] );

            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $pvz_opt = array_map(function( $v ){
                return ['value' => $v['pvz'], 'text' => $v['pvz']];
            }, $items);
            array_unshift( $pvz_opt, ['value' => '', 'text' => 'Не выбранно']);


            // $items = [];
            // $items[] = [
            //     'image' => '',
            //     'art' => '78858215',
            //     'color' => 'Розовый',
            //     'size' => 'XS',
            //     'fio' => 'Ивано Иван Иванович',
            //     'pvz' => 'г Москва, Улица Покрышкина 8к2',
            //     'status' => 'Готов к выдаче',
            //     'code' => '148',
            // ];

            foreach ($items as &$item) {
                if ( @$cache[$item['art']] ) {
                    $_item = $cache[$item['art']];
                } else {
                    $_result = $this->fаindService->findByArt(['art' => $item['art']]);
                    $_item = @$_result['items'][0];
                    $cache[$item['art']] = $_item;
                }

                $item['image'] = @$_item['image'];
            }
            unset($item);
            unset($_item);
        }

        if ( $type == 'forme' ) {
            $headers = [
                ["text" => "Дата", "value" => 'date', 'sortable' => false],
                ["text" => "План", "value" => 'plan', 'sortable' => false],
                ["text" => "Получено товаров", "value" => 'count', 'sortable' => false],
                ["text" => "Статус", "value" => 'status', 'sortable' => false],
                ["text" => "", "value" => 'action', 'sortable' => false],
            ];


            $sql = "
                SELECT
                  DATE(cl.grafik) AS `date`,
                  COUNT(*) plan,
                  SUM(
                    IF (
                      cl.status = 'Получен' || cl.status = 'Забран',
                      1,
                      0
                    )
                  ) AS `count`,
                  GROUP_CONCAT(CONCAT(cl.id, '|', cl.status)) AS statuses,
                  '' AS `status`,
                  '' AS `group`
                FROM
                  `client` cl
                WHERE 1
                  AND cl.task1 = ?
                  AND cl.mp = 'wb'
                  AND cl.`type` = 'выкуп'
                  AND cl.kto_zabirat = 'RATE-THIS'
                  AND cl.`group` IS NOT NULL
                GROUP BY DATE(cl.grafik)
            ";

            $stmt = $dbh->prepare( $sql );
            $stmt->execute( [$task1] );

            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as &$item) {
                $item['group'] = crc32($item['date']);

                $item['count'] = (int) $item['count'];

                $_statuses = [];
                $statuses_exp = explode(',', $item['statuses']);
                foreach ($statuses_exp as $_st) {
                    $_status = explode('|', $_st);
                    $_status = $_status[1];
                    $_status = $this->calcStatus($_status);
                    $_statuses[] = $_status;
                }
                unset($item['statuses']);

                $_statuses = array_unique($_statuses);
                $_statuses = array_values($_statuses);

                if ( count($_statuses) == 1 ) {
                    $item['status'] = $_statuses[0];
                } else if (count($_statuses) > 1){
                    $item['status'] = $this->calcStatus('ожидает');
                }

            }


        }

        return [
            'headers' => $headers,
            'items' => $items,
            'pvz_opt' => $pvz_opt
        ];
    }




    public function getByGroup( string $group, array $params ): array
    {

        $dbh = $this->rateDB->connection;


        $output = [];
        $output['success'] = false;

        $period = new DatePeriod(
             new DateTime('2010-10-01'),
             new DateInterval('P1D'),
             new DateTime('2010-10-05')
        );

        $period = new DatePeriod(
             new DateTime(date('Y-m-d', strtotime(' -100 day'))),
             new DateInterval('P1D'),
             new DateTime(date('Y-m-d', strtotime(' +100 day')))
        );

        $dates = array();
        foreach ($period as $key => $value) {
            $dates[ crc32($value->format('Y-m-d')) ] = $value->format('Y-m-d');
        }

        if ( !@$dates[ $group ] ) {
            $output['msg'] = 'Group not found';
            return $output;
        }

        $output['date'] = $dates[ $group ];

        $headers = [
            ["text" => "Фото", "value" => 'image', 'sortable' => false],
            ["text" => "Артикул", "value" => 'art', 'sortable' => false],
            ["text" => "Цвет", "value" => 'color', 'sortable' => false],
            ["text" => "Размер", "value" => 'size', 'sortable' => false],
            ["text" => "Дата покупки", "value" => 'date_buy', 'sortable' => false],
            ["text" => "Чек", "value" => 'cheque', 'sortable' => false],
            ["text" => "Дата получения", "value" => 'date_get', 'sortable' => false],
            ["text" => "Статус", "value" => 'status', 'sortable' => false],
        ];

        $sql = "
            SELECT
              '' AS image,
              '' AS color,
              cl.`article` AS art,
              cl.`status`,
              cl.`size`,
              cl.`date_buy`,
              cl.`date_get`,
              cl.check AS cheque
            FROM
              `client` cl
            WHERE 1
              AND CRC32(DATE(cl.grafik)) = ?
              AND cl.`type` = 'выкуп'
        ";

        $stmt = $dbh->prepare( $sql );
        $stmt->execute( [$group] );

        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $cache = [];

        foreach ($items as &$item) {
            if ( @$cache[$item['art']] ) {
                $_item = $cache[$item['art']];
            } else {
                $_result = $this->fаindService->findByArt(['art' => $item['art']]);
                $_item = @$_result['items'][0];
                $cache[$item['art']] = $_item;
            }
            $item['image'] = @$_item['image'];
            $item['status'] = $this->calcStatus( $item['status'] );
        }


        $output['headers'] = $headers;
        $output['items'] = $items;

        return $output;
    }
}
