<?php

namespace App\Service;

use App\Exceptions\AppException;
use App\Support\Connection;
use Carbon\Carbon;
use \PDO;

use App\Support\Auth;

class ReviewsService
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

        // > Alex:
        // В отзывах всего 5 статусов
        // Получен= согласование
        // Опубликовать=опубликовать
        // Модерация= модерация
        // Опубликован=опубликован
        // Удалён= удалён

        // > Alex:
        // Ещё раз, пока товар не получит статус получен, в отзывах он не отображается.
        // Отмена
        // Возврат тоже самое,
        // Товар есть в отзывах только по достижению указанных статусов

        // Получен
        // Опубликовать
        // Модерация
        // Опубликован
        // Удалён

        // С учетом того что попадает на верх как ожидают публикации только те товары, которые имеют type отзыв

        $statusKeys = [

        ];


        foreach ($statusKeys as $_key => $_value) {
            if ( preg_match('#'.$value.'#ui', $_key) ) {
                $value = $_value;
                break;
            }
        }

        $statuses = [
            '4' => 'Получен|succses',
            '5' => 'Опубликовать|plan',
            '6' => 'Модерация|process',
            '7' => 'Опубликован|succses',
            '8' => 'Удалён|dunger',

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

        $headers = [];
        $items = [];

        $cache = [];

        if ( !$type ) {

            $headers = [
                ["text" => "Фото", "value" => 'image', 'sortable' => false],
                ["text" => "Артикул", "value" => 'art', 'sortable' => false],
                ["text" => "Цвет", "value" => 'color', 'sortable' => false],
                ["text" => "Размер", "value" => 'size', 'sortable' => false],
                ["text" => "Рейтинг", "value" => 'rating', 'sortable' => false],
                ["text" => "Доступно отзывов", "value" => 'count', 'sortable' => false],
                ["text" => "", "value" => 'action', 'sortable' => false],
            ];

            $items = [];
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

        if ( $type == 'other' ) {
            $headers = [
                ["text" => "Фото", "value" => 'image', 'sortable' => false],
                ["text" => "Артикул", "value" => 'art', 'sortable' => false],
                ["text" => "Цвет", "value" => 'color', 'sortable' => false],
                ["text" => "Размер", "value" => 'size', 'sortable' => false],
                ["text" => "Рейтинг", "value" => 'rating', 'sortable' => false],
                ["text" => "Доступно отзывов", "value" => 'count', 'sortable' => false],
                ["text" => "", "value" => 'action', 'sortable' => false],
            ];

            $items = [];

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

        return [
            'headers' => $headers,
            'items' => $items,
        ];
    }




    public function getByGroup( string $group, array $params ): array
    {

        $dbh = $this->rateDB->connection;

        $art = $group;

        $cache = [];

        $headers = [
            ["text" => "Фото", "value" => 'image', 'sortable' => false],
            ["text" => "Артикул", "value" => 'art', 'sortable' => false],
            ["text" => "Цвет", "value" => 'color', 'sortable' => false],
            ["text" => "Размер", "value" => 'size', 'sortable' => false],
            ["text" => "Текст отзыва", "value" => 'review', 'sortable' => false, 'width'=> '300px'],
            ["text" => "Кол-во звезд", "value" => 'rating', 'sortable' => false],
            ["text" => "Фото", "value" => 'photos', 'sortable' => false, 'width'=> '200px'],
            ["text" => "Согласован?", "value" => 'agreed', 'sortable' => false],
            ["text" => "Стутус", "value" => 'status', 'sortable' => false],
            ["text" => "Дата публикации", "value" => 'date', 'sortable' => false],
            ["text" => "", "value" => 'action', 'sortable' => false],
        ];

        $sql = "
            SELECT
                cl.id,
                '' AS `image`,
                cl.article AS art,
                '' AS color,
                cl.photo as photos,
                cl.size,
                cl.text_otziv AS review,
                cl.rating_otziv AS rating,
                0 as agreed,
                cl.`status`,
                cl.date_otziv as date
            FROM `client` cl
            WHERE cl.article = ?
            AND cl.status IN ('Получен','Опубликовать','Модерация','Опубликован','Удалён')
            -- AND cl.mp = 'wp'
            AND cl.`type`= 'отзыв'
        ";

        $stmt = $dbh->prepare( $sql );
        $stmt->execute( [$art] );
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $domain = config('app.domain');

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

            if ( !@$item['agreed'] ) $item['agreed'] = 0;
            else $item['agreed'] = (int) $item['agreed'];

            if ( !@$item['rating'] ) $item['rating'] = 0;
            else $item['rating'] = (int) $item['rating'];

            $item['review'] = (string) $item['review'];

            if ( @$item['photos'] ) {
                if ( $_data = json_decode($item['photos'], true) ) {
                    $item['photos'] = $_data;
                }
            }
            if ( !@$item['photos'] ) $item['photos'] = [];
            if ( !is_array(@$item['photos']) ) $item['photos'] = [];

            if ( @$item['photos'] && is_array(@$item['photos']) && count($item['photos']) > 0 ) {
                $item['photos'] = array_map(function($v) use ($domain){
                    return $v;
                }, $item['photos']);
            }

            $item['review_editable'] = false;
            $item['need_save'] = false;
        }
        unset($item);
        unset($_item);

        return [
            'headers' => $headers,
            'items' => $items,
            'art' => $art
        ];
    }

    public function save( array $params ): array
    {

        $output = [];
        $output['succses'] = false;

        $photos = @$params['item']['photos'];
        $id = @$params['item']['id'];
        $review = @$params['item']['review'];
        $rating = @$params['item']['rating'];

        $files = [];

        foreach ($photos as $index => $photo) {
            if ( @$photo ) {
                $files[] = $this->base64ToImage( $photo, $id, $index );
            }
        }

        $sql = '
            UPDATE `client` cl
            SET cl.rating_otziv = ?, cl.text_otziv = ?, cl.photo = ?
            WHERE cl.id = ?
        ';
        $dbh = $this->rateDB->connection;
        $stmt = $dbh->prepare( $sql );
        if ( $stmt->execute([
            $rating,
            $review,
            json_encode($files),
            $id
        ]) ) {

            if ( $stmt->rowCount() > 0 ) {
                $output['succses'] = true;
            }

        } else {
            throw new \Exception('Не удалсь сохранить');
        }

        return $output;
    }

    private function base64ToImage( $data, $id, $index ) {

        if ( !@$data ) return false;
        if ( preg_match('#\/img\/#', $data) ) return $data;

        if (preg_match('/^data:image\/(\w+);base64,/', $data, $type)) {
            $data = substr($data, strpos($data, ',') + 1);
            $type = strtolower($type[1]); // jpg, png, gif

            if (!in_array($type, [ 'jpg', 'jpeg', 'gif', 'png' ])) {
                throw new \Exception('invalid image type');
            }
            $data = str_replace( ' ', '+', $data );
            $data = base64_decode($data);

            if ($data === false) {
                throw new \Exception('base64_decode failed');
            }
        } else {
            throw new \Exception('did not match data URI with image data');
        }

        $img_name = "img_{$index}.{$type}";
        $path = config('app.root_dir')."/public/img/{$id}/";

        if ( !file_exists($path) ) {
            if (!mkdir($path, 0777, true)) {
                throw new \Exception('Не удалось создать директории...');
            }
        }

        if ( file_exists($path.$img_name) ) unlink($path.$img_name);

        if ( !file_put_contents($path.$img_name, $data) ) {
            throw new \Exception('Не удалось сохранить файл...');
        } else {
            return "/img/{$id}/".$img_name;
        }
    }
}
