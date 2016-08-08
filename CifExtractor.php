<?php

namespace Cif;

class CifExtractor
{

    private static $cookie = "ASP.NET_SessionId=j2sdh0554udokff4qqxndp34; TS011d2310=015dd60f3ed592200c66452a304606b53fcddfb71a6a8928d9499022bcba5ba42d9a0532abcf386f596b18c5f38d201ba15851843b; TS01ac0ef4=015dd60f3eeb1534951fd0644244fe0246cdcc4ea0b3361402e0ac0ddf55a573f8b1693bb5ebf21076f5da6260daa96907f3d548ff1192340108e45723dd27e716676ce311";
    private static $lang = "FR";

    private static $letters = ['b', 's', 'd', 'e'];

    private static $api = "http://apps.who.int/classifications/icfbrowser/Browse.aspx?code=%s";
    private static $limit = 10000;

    private static $host= "127.0.0.1";
    private static $db_name = "cif";
    private static $db_user = "cif";
    private static $db_password = "cif";

    private static $pdo = null;

    private static $counter = 0;

    public static function initialize()
    {
        $dsn = "mysql:host=" . self::$host . ";dbname=" . self::$db_name . ";charset=utf8";
        $opt = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        self::$pdo = new \PDO($dsn, self::$db_user, self::$db_password, $opt);

        self::createDatabase();
    }

    public static function createDatabase()
    {
        $sql = "
          CREATE DATABASE IF NOT EXISTS `" . self::$db_name . "`;
        ";

        $sql2 = "
          CREATE TABLE IF NOT EXISTS `classification_" . self::$lang . "`
          (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `parent_code` varchar(10) DEFAULT NULL,
          `code` varchar(10) NOT NULL,
          `title` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
          `description` longtext COLLATE utf8_unicode_ci,
          `inclusions` longtext COLLATE utf8_unicode_ci,
          `exclusions` longtext COLLATE utf8_unicode_ci,
          PRIMARY KEY (`id`)
          ) ENGINE=InnoDB AUTO_INCREMENT=0 DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
        ";

        $sql3 = "TRUNCATE TABLE `classification_" . self::$lang . "`;";

        self::$pdo->query($sql);
        self::$pdo->query($sql2);
        self::$pdo->query($sql3);

        echo "DB and table created or found. Starting fresh.\n";
    }

    public static function insertRecord($data_array)
    {
        $sql = "
        INSERT INTO `classification_" . self::$lang . "` (
          parent_code,
          code,
          title,
          description,
          inclusions,
          exclusions
        ) 
        VALUES (
          :parent_code,
          :code,
          :title,
          :description,
          :inclusions,
          :exclusions
          );";

        $sth = self::$pdo->prepare($sql, array(\PDO::ATTR_CURSOR => \PDO::CURSOR_FWDONLY));
        $sth->execute(array(
            ':parent_code' => $data_array['parent_code'],
            ':code' => $data_array['code'],
            ':title' => $data_array['title'],
            ':description' => $data_array['description'],
            ':inclusions' => $data_array['inclusions'],
            ':exclusions' => $data_array['exclusions'],
          ));

        self::$counter++;
        echo "inserted.\n";
    }

    public static function cycle($letter)
    {
        for ($code=0; $code < self::$limit; $code++) {
            // Compute parent code
            if ($code < 100) {
                $parent_code = null;
            } elseif ($code < 1000) {
                $parent_code = substr($code."", 0, 1);
            } else {
                $parent_code = substr($code."", 0, 3);
            }

            $return_array = self::extract($letter, $parent_code, $code);
            if ($return_array !== false) {
                self::insertRecord($return_array);
            }
        }

    }

    public static function extract($letter, $parent_code, $code)
    {
        echo "Extracting from " . $letter . $code . " ... ";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, sprintf(self::$api, $letter . $code));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Cookie: " . self::$cookie));
        $output = curl_exec($ch);
        curl_close($ch);

        $dom = new \DOMDocument;
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $output);

        $title =  trim($dom->getElementById('Title')->nodeValue);
        $description =  trim($dom->getElementById('Description')->nodeValue);

        $error = (strpos($title, "Error") !== false);

        if ($error) {
            echo "\033[31mnothing\033[0m. Skipping.\n";
            return false;
        } else {
            // remove starting code from title
            if (substr($title, 0, strlen($letter . $code)) === ($letter . $code)) {
                $title = trim(substr($title, strlen(($letter . $code))));
            }

            echo "\033[32mfound\033[0m ... '" . $title . "' ";
            return array(
              "code" => $letter . $code,
              "parent_code" => $letter . $parent_code,
              "title" => $title,
              "description" => $description,
              "exclusions" => trim($dom->getElementById('Exclusions')->nodeValue),
              "inclusions" => trim($dom->getElementById('Inclusions')->nodeValue),
            );
        }
    }

    public static function extractRoot($letter)
    {
        echo "Extracting root from " . $letter . " ... ";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, sprintf(self::$api, $letter));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Cookie: " . self::$cookie));
        $output = curl_exec($ch);
        curl_close($ch);

        $dom = new \DOMDocument;
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $output);

        $title =  trim($dom->getElementById('Title')->nodeValue);

        echo "\033[32mfound\033[0m ... '" . $title . "' ";

        return array(
          "code" => $letter,
          "parent_code" => null,
          "title" => $title,
          "description" => null,
          "exclusions" => null,
          "inclusions" => null,
        );
    }


    public static function run()
    {

        // roots
        echo "\n== Extracting roots == \n";
        foreach (self::$letters as $key => $letter) {
            $return_array = self::extractRoot($letter);
            if ($return_array !== false) {
                self::insertRecord($return_array);
            }
        }

        foreach (self::$letters as $key => $letter) {
            echo "\n== Extracting children for category : " . $letter . " == \n";
            self::cycle($letter);
        }

        echo "\nInserted " . self::$counter . " lines in DB.\n";
    }
}


CifExtractor::initialize();
CifExtractor::run();
