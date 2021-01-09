<?php

class Parser{
    public $pars;

    public function __construct(){
        require 'simple_html_dom.php';
        $this->pars = new simple_html_dom();

    }

    /**
     * @param $pageUrl
     * @param null $token
     * @param null $params
     * @return mixed
     */
    public function sendRequest($pageUrl, $token = NULL, $params = NULL){
        $base_url = 'yandex.ru';
        $pause_time = 2;
        $retry = 0;
        if($token != NULL) {
            $token = base64_decode($token, 1);
            $token = explode(':', $token);
            if(!empty($token[0]) AND !empty($token[1]))
            $cookie = "h_id=" . trim($token[0]) . ";s_id=" . trim($token[1]) . ";expires=" . $this->unix_to_kfu(time()) . "; path=/; domain=kpfu.ru";
            else
                $cookie = NULL;
        }else
            $cookie = NULL;
        $error_page = array();
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.198 Safari/537.36");
        curl_setopt($ch, CURLOPT_ENCODING ,"");
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        if(!empty($params)){
            //curl_setopt($curl, CURLOPT_POST, true);
            //curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
            $pageUrl = $pageUrl.'?'.http_build_query($params);
        }
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); // Автоматом идём по редиректам
        curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, 0); // Не проверять SSL сертификат
        curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, 0); // Не проверять Host SSL сертификата
        curl_setopt($ch, CURLOPT_URL, $pageUrl); // Куда отправляем
        curl_setopt($ch, CURLOPT_REFERER, $base_url); // Откуда пришли
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // Возвращаем, но не выводим на экран результат
        $response['html'] = curl_exec($ch);
        $response['html'] = iconv('WINDOWS-1251', 'UTF-8', $response['html']);
        $info = curl_getinfo($ch);
        if($info['http_code'] != 200 && $info['http_code'] != 404) {
            $error_page[] = array(1, $pageUrl, $info['http_code']);
            if($retry) {
                sleep($pause_time);
                $response['html'] = curl_exec($ch);
                $info = curl_getinfo($ch);
                if($info['http_code'] != 200 && $info['http_code'] != 404)
                    $error_page[] = array(2, $pageUrl, $info['http_code']);
            }
        }
        $response['code'] = $info['http_code'];
        $response['errors'] = $error_page;
        curl_close($ch);
        return $response;
    }

    /**
     * @param $time
     * @return string
     */
    public function unix_to_kfu($time){
        return strtoupper(date("D")) . ", " . strtoupper(date("d-M-Y")) . " " . date("h:i:s") . " GMT";
    }

    /**
     * @param $response
     * @return bool
     * @throws Exception
     */
    private function err_check($response){
        if($response['code'] == 200 && !is_null($response['html']))
            return true;
        else
            throw new Exception($response['code']);
    }

    /**
     * @param $status
     * @param $result
     * @param null $add_info
     * @param null $err_text
     * @param null $response
     * @return array
     */
    private function return($status, $result, $add_info = NULL, $err_text = NULL, $response = NULL){
        unset($response['html']);
        if(empty($result) && empty($add_info) && empty($err_text)) $err_text = "Неизвестная ошибка";
        return array(
            'status' => $status,
            'result' => $result,
            'add_info' => $add_info,
            'err_text' => $err_text,
            'response' => $response,
        );
    }

    /** Парсит токен после авторизации
     * @param $html
     * @return array|bool
     */
    private function parse_token($html){
        preg_match("/.*main_blocks\.startpage\?(.*)'.*/", $html, $matches);
        //$matches[1]: p2=13296448175513173940279347215275&p_h=4D3F4C1807041AA9F19B4D9F576DCE54
        if(!empty($matches[1])) {
            $params = explode('&', $matches[1]);
            $p2 = explode('=', $params[0])[1]; //s_id
            $p_h = explode('=', $params[1])[1]; //h_id
            $cookie = array(
                's_id' => $p2,
                'h_id' => $p_h
            );
            $this->pars->clear();
            return $cookie;
        }else
            return false;
    }

    /** Парсит алерт
     * @param $html
     * @return mixed
     */
    public function parse_alert($html){
        preg_match("/.*alert.?[',\"](.*)[\",'].?.*;/", $html, $matches);
        if(!empty($matches[1]))
            return $matches[1];
        else
            return false;
    }

    /** Авторизоваться на сайте КФУ и получить токен
     * @param $login: ЛОГИН КФУ
     * @param $pass: ПАРОЛЬ КФУ
     * @return array: Токен
     */
    public function auth($login, $pass){
        $link = "https://shelly.kpfu.ru/e-ksu/private_office.authscript?p_login=".$login."&p_pass=".$pass;
        try {
            $result = $this->sendRequest($link);
        }catch(Throwable $error) {
            return $this->return(false, NULL, NULL, $error->getMessage());
        }

        $alert = $this->parse_alert($result['html']);
        $token = $this->parse_token($result['html']);

        if(empty($token['h_id']) || empty($token['s_id'])) return $this->return(false, NULL, NULL, $alert, $result);

        $token = base64_encode($token['h_id'] . ':' . $token['s_id']);
        return $this->return(true, $token, $alert, NULL, $result);
    }

    /** Парсинг меню "Электронная зачетная книжка"
     * @param $token: Токен
     * @param int $course: Курс
     * @return array
     */
    public function parse_menu_7($token, $course = 1){
        $link = "https://shelly.kpfu.ru/e-ksu/SITE_STUDENT_SH_PR_AC.score_list_book_subject?p_menu=7&p_course=".$course;
        try {
            $result = $this->sendRequest($link, $token);
            $this->err_check($result);
        }catch(Throwable $error) {
            return $this->return(false, NULL, NULL, $error->getMessage());
        }

        $alert = $this->parse_alert($result['html']);
        if(!empty($alert)) return $this->return(false, NULL, NULL, $alert, $result);

        $this->pars->load($result['html']);
        try{
            //$parse = $this->pars->find("table")[1]->find('thead tr td');
            $parse = $this->pars->find("table");
            if(isset($parse[1]))
                $parse = $parse[1]->find("thead tr td");
            else
                throw new Exception('Таблица не найдена');

        }catch(Throwable $error){
            return $this->return(false, NULL, NULL, $error->getMessage(), $result);
        }

        foreach($parse as $key => $value)
            $thead[] = $value->plaintext;

        try{
            //$parse = $this->pars->find("table")[1]->find('tbody tr');
            $parse = $this->pars->find("table");
            if(isset($parse[1]))
                $parse = $parse[1]->find("tbody tr");
            else
                throw new Exception('Таблица не найдена');
        }catch(Throwable $error){
            return $this->return(false, NULL, NULL, $error->getMessage(), $result);
        }

        foreach($parse as $key => $value){
            preg_match_all('/<td.+?>(.+?)<\/td>/', $value->innertext, $matches);
            $count_matches = count($matches[1]);

            /* Заполняем отсутствующие ячейки знаком минус/*/
            for($i = 1; $i < (count($thead) - $count_matches + 1); $i++)
                $matches[1][] = '-';

            if(!empty($matches[0][0])){
                $text = str_replace('&nbsp;', '', $matches[1]);
                $tbody[] = $text;
            }
        }

        $return = array('head' => $thead, 'body' => $tbody);
        $this->pars->clear();
        return $this->return(true, $return, $alert, NULL, $result);
    }

    /** Спарсить курс на котором учится студент
     * @param $token
     * @return array|string
     * @throws Exception
     */
    public function parse_course_student($token){
        $link = 'https://shelly.kpfu.ru/e-ksu/SITE_STUDENT_SH_PR_AC.score_list_book_subject?p_menu=7';
        try{
            $result = $this->sendRequest($link, $token);
            $this->err_check($result);
        }catch(Throwable $error) {
            return $this->return(false, NULL, NULL, $error->getMessage());
        }
        $alert = $this->parse_alert($result['html']);
        if(!empty($alert)) return $this->return(false, NULL, NULL, $alert, $result);

        $this->pars->load($result['html']);
        $parse = $this->pars->find("div[class=courses] span[class=course]");
        if(empty($parse)) throw new Exception('Не удалось определить курс');
        foreach($parse as $key => $value){
            $course = $value->plaintext;
        }
        //return $this->return(true, $info);
        $this->pars->clear();
        $course = str_replace('курс', '', $course);
        return $this->return(true, trim($course));
    }

    public function parse_student($token){
        $link = 'https://shelly.kpfu.ru/e-ksu/new_stud_personal.stud_anketa';
        try{
            $result = $this->sendRequest($link, $token);
            $this->err_check($result);
        }catch(Throwable $error) {
            return $this->return(false, NULL, NULL, $error->getMessage());
        }

        $alert = $this->parse_alert($result['html']);
        if(!empty($alert)) return $this->return(false, NULL, NULL, $alert, $result);

        $this->pars->load($result['html']);
        $parse = $this->pars->find("div[class=left]")[0]->find("div[class=info-item]");
        foreach($parse as $key => $value){
            $name = trim($value->find("span[class=name]", 0)->plaintext, ':');
            $value = $value->find("span[class=value]", 0)->plaintext;
            if(strpos($name, 'группы') !== false) $info['group'] = $value;
            if(strpos($name, 'Институт') !== false) $info['institute'] = $value;
        }
        $course = $this->parse_course_student($token);
        if($course['status']) $info['course'] = $course['result'];
        //test
        return $this->return(true, $info);
    }
}