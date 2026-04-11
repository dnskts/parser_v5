<?php
/**
 * ============================================================
 * СПРАВОЧНИК КОНСТАНТ SmartTravel
 * ============================================================
 *
 * Маппинги полей SmartTravel (РЖД-ЦПР «Инновационная мобильность»)
 * на значения формата ORDER/RSTLS.
 *
 * Источник: data_exchange_push_and_pull_20.03.24.pdf (таблицы 5–12, 21)
 *           transliteration-tables.docx (таблицы транслитерации)
 * ============================================================
 */

class SmartTravelConstants
{
    /**
     * Тип операции (таблица 21 PDF)
     * OperationType → STATUS в ORDER
     */
    public static function getOperationTypeMap()
    {
        return array(
            'Purchase' => 'продажа',
            'Return'   => 'возврат',
            'Exchange' => 'обмен',
        );
    }

    /**
     * Пол пассажира (таблица 5 PDF)
     * Sex → PASSENGER_GENDER
     */
    public static function getGenderMap()
    {
        return array(
            'Male'    => 'Мужчина',
            'Female'  => 'Женщина',
            'NoValue' => '',
        );
    }

    /**
     * Тип документа (таблица 10 PDF — полная)
     * DocumentType → PASSENGER_DOC_TYPE
     */
    public static function getDocTypeMap()
    {
        return array(
            'RussianPassport'                => 'Паспорт РФ',
            'RussianForeignPassport'         => 'Загранпаспорт РФ',
            'ForeignPassport'                => 'Национальный паспорт',
            'BirthCertificate'               => 'Свидетельство о рождении',
            'MilitaryCard'                   => 'Военный билет',
            'MilitaryOfficerCard'            => 'Удостоверение военнослужащего',
            'ReturnToCisCertificate'         => 'Свидетельство о возвращении из стран СНГ',
            'DiplomaticPassport'             => 'Дипломатический паспорт',
            'ServicePassport'                => 'Служебный паспорт',
            'SailorPassport'                 => 'Удостоверение моряка',
            'StatelessPersonIdentityCard'    => 'Удостоверение лица без гражданства',
            'ResidencePermit'                => 'Вид на жительство',
            'RussianTemporaryIdentityCard'   => 'Временное удостоверение личности',
            'UssrPassport'                   => 'Паспорт СССР',
            'MedicalBirthCertificate'        => 'Медицинское свидетельство о рождении',
            'LostPassportCertificate'        => 'Справка об утере паспорта',
            'PrisonReleaseCertificate'       => 'Справка об освобождении',
            'CertificateOfTemporaryAsylum'   => 'Свидетельство о временном убежище',
            'MilitaryTemporaryCard'          => 'Временное удостоверение (взамен военного билета)',
            'ReserveOfficerMilitaryCard'     => 'Военный билет офицера запаса',
            'UssrForeignPassport'            => 'Загранпаспорт СССР',
            'RefugeeIdentity'               => 'Удостоверение беженца',
            'RefugeeCertificate'             => 'Свидетельство о рассмотрении ходатайства беженца',
            'RussianTemporaryLivingCertificate' => 'Разрешение на временное проживание в РФ',
            'OfficerCertificate'             => 'Удостоверение офицера',
            'MinistryMarineFleetPassport'    => 'Паспорт Минморфлота',
            'ForeignBirthCertificate'        => 'Иностранное свидетельство о рождении',
            'Other'                          => 'Иной документ',
            'ConvictedPersonIdentity'        => 'Документ осужденного',
            'AcknowledgmentOfIdentityOfAForeignCitizenOrStatelessPerson' => 'Заключение об установлении личности',
            'CertificateIssuedForAForeignCitizenGoingToDiplomaticOffice' => 'Справка для дипломатического представительства',
            'AnotherDocumentOfAForeignCitizenRecognizedInTheRussianFederation' => 'Иной документ иностранного гражданина',
        );
    }

    /**
     * Тип вагона (таблица 7 PDF)
     * CarType → читаемое название
     */
    public static function getCarTypeMap()
    {
        return array(
            'Unknown'      => 'Не определён',
            'Shared'       => 'Общий',
            'Soft'         => 'Мягкий',
            'Luxury'       => 'Люкс',
            'Compartment'  => 'Купе',
            'ReservedSeat' => 'Плацкарт',
            'Sedentary'    => 'Сидячий',
            'Baggage'      => 'Багажный',
        );
    }

    /**
     * Статус бланка (таблица 11 PDF)
     * BlankStatus → читаемое название
     */
    public static function getBlankStatusMap()
    {
        return array(
            'ElectronicRegistrationAbsent'           => 'Без ЭР',
            'ElectronicRegistrationPresent'          => 'С ЭР',
            'NotConfirmed'                           => 'Не подтверждён',
            'Voided'                                 => 'Аннулирован',
            'Returned'                               => 'Возвращён',
            'PlacesReturned'                         => 'Возвращены места',
            'VoucherIssued'                          => 'Выдан посадочный купон',
            'TripWasInterrupted'                     => 'Прерывание поездки',
            'TripWasInterruptedAndResumedAfter'      => 'Прерывание с возобновлением',
            'Unknown'                                => 'Неизвестен',
        );
    }

    /**
     * Тип ЖД-сервиса (таблица 6 PDF)
     * ServiceType → читаемое название
     */
    public static function getServiceTypeMap()
    {
        return array(
            'Tickets'                        => 'ЖД-билеты',
            'CrimeaTalons'                   => 'Талоны в Крым',
            'BusTalons'                      => 'Талоны на автобус',
            'AbkhaziaTalons'                 => 'Талоны в Абхазию',
            'RailwayNinetyPlusCoupons'       => 'Заявка 90+',
            'RailwayGroupTicketFee'          => 'Сбор за групповую перевозку',
            'RailwayGroupTicketReservation'  => 'Бронирование мест (групповое)',
            'RailwayGroupTicket'             => 'Групповой ЖД билет',
            'AutomotiveTalons'               => 'Талоны на автотранспорт',
            'SuburbanTickets'                => 'Пригородные билеты',
        );
    }

    /**
     * Категория пассажира → PASSENGER_AGE
     */
    public static function getPassengerCategoryMap()
    {
        return array(
            'Adult'    => 'ADULT',
            'Child'    => 'CHILD',
            'Infant'   => 'INFANT',
            'Senior'   => 'SENIOR',
            'Youth'    => 'YOUTH',
            'Disabled' => 'DISABLED',
        );
    }

    /**
     * Тип комиссии (таблица 9 PDF)
     * ClientFeeCalculation: Charge = сколько взято с клиента, Profit = сколько отдали
     */
    public static function getFeeTypeMap()
    {
        return array(
            'Charge' => 'Сбор',
            'Profit' => 'Вознаграждение',
        );
    }

    /**
     * Маппинг пола — статический метод для парсера
     */
    public static function mapGender($sex)
    {
        $map = self::getGenderMap();
        return isset($map[$sex]) ? $map[$sex] : '';
    }

    /**
     * Маппинг типа документа — статический метод для парсера
     */
    public static function mapDocType($docType)
    {
        $map = self::getDocTypeMap();
        return isset($map[$docType]) ? $map[$docType] : (string)$docType;
    }

    /**
     * Маппинг типа операции — статический метод для парсера
     */
    public static function mapOperationType($operationType)
    {
        $map = self::getOperationTypeMap();
        return isset($map[$operationType]) ? $map[$operationType] : (string)$operationType;
    }

    /**
     * Маппинг типа вагона — статический метод для парсера
     */
    public static function mapCarType($carType)
    {
        $map = self::getCarTypeMap();
        return isset($map[$carType]) ? $map[$carType] : (string)$carType;
    }

    /**
     * Маппинг категории пассажира — статический метод для парсера
     */
    public static function mapPassengerCategory($category)
    {
        $map = self::getPassengerCategoryMap();
        return isset($map[$category]) ? $map[$category] : 'ADULT';
    }

    // ==========================================================
    // ТРАНСЛИТЕРАЦИЯ (из transliteration-tables.docx)
    // ==========================================================

    /**
     * Таблица транслитерации кириллических знаков (60 символов).
     * Кириллица → Латиница (для ЖД-бланков).
     * По умолчанию НЕ применяется — имена хранятся как есть.
     */
    public static function getTransliterationMap()
    {
        return array(
            'А' => 'A',    'а' => 'A',
            'Б' => 'B',    'б' => 'B',
            'В' => 'V',    'в' => 'V',
            'Г' => 'G',    'г' => 'G',
            'Д' => 'D',    'д' => 'D',
            'Е' => 'E',    'е' => 'E',
            'Ё' => 'E',    'ё' => 'E',
            'Ж' => 'ZH',   'ж' => 'ZH',
            'З' => 'Z',    'з' => 'Z',
            'И' => 'I',    'и' => 'I',
            'Й' => 'I',    'й' => 'I',
            'К' => 'K',    'к' => 'K',
            'Л' => 'L',    'л' => 'L',
            'М' => 'M',    'м' => 'M',
            'Н' => 'N',    'н' => 'N',
            'О' => 'O',    'о' => 'O',
            'П' => 'P',    'п' => 'P',
            'Р' => 'R',    'р' => 'R',
            'С' => 'S',    'с' => 'S',
            'Т' => 'T',    'т' => 'T',
            'У' => 'U',    'у' => 'U',
            'Ф' => 'F',    'ф' => 'F',
            'Х' => 'KH',   'х' => 'KH',
            'Ц' => 'TS',   'ц' => 'TS',
            'Ч' => 'CH',   'ч' => 'CH',
            'Ш' => 'SH',   'ш' => 'SH',
            'Щ' => 'SHCH', 'щ' => 'SHCH',
            'Ъ' => 'IE',   'ъ' => 'IE',
            'Ы' => 'Y',    'ы' => 'Y',
            'Ь' => '',     'ь' => '',
            'Э' => 'E',    'э' => 'E',
            'Ю' => 'IU',   'ю' => 'IU',
            'Я' => 'IA',   'я' => 'IA',
            // Расширенная кириллица
            'Ӣ' => 'I',    'ӣ' => 'I',
            'Ң' => 'N',    'ң' => 'N',
            'Ғ' => 'G',    'ғ' => 'G',
            'Қ' => 'K',    'қ' => 'K',
            'Ұ' => 'U',    'ұ' => 'U',
            'Ү' => 'U',    'ү' => 'U',
            'Ў' => 'U',    'ў' => 'U',
            'Ҳ' => 'KH',   'ҳ' => 'KH',
            'Ҷ' => 'CH',   'ҷ' => 'CH',
            'Һ' => 'C',    'һ' => 'C',
            'Ќ' => 'K',    'ќ' => 'K',
            'Љ' => 'LJ',   'љ' => 'LJ',
            'Њ' => 'NJ',   'њ' => 'NJ',
            'Ə' => 'A',    'ə' => 'A',
        );
    }

    /**
     * Таблица транслитерации многонациональных (диакритических) знаков (94 символа).
     * Латиница с диакритикой → чистая латиница.
     */
    public static function getMultinationalTransliterationMap()
    {
        return array(
            'À' => 'A',  'Á' => 'A',  'Â' => 'A',  'Ã' => 'A',  'Ä' => 'A',  'Å' => 'A',
            'Æ' => 'AE', 'È' => 'E',  'É' => 'E',  'Ê' => 'E',  'Ë' => 'E',
            'Ì' => 'I',  'Í' => 'I',  'Î' => 'I',  'Ï' => 'I',
            'Ñ' => 'N',
            'Ò' => 'O',  'Ó' => 'O',  'Ô' => 'O',  'Õ' => 'O',  'Ö' => 'O',  'Ø' => 'OE',
            'Ù' => 'U',  'Ú' => 'U',  'Û' => 'U',  'Ü' => 'U',
            'Ý' => 'Y',  'Þ' => 'TH',
            'Ā' => 'A',  'Ă' => 'A',  'Ą' => 'A',
            'Ç' => 'C',  'Ć' => 'C',  'Ĉ' => 'C',  'Ċ' => 'C',  'Č' => 'C',
            'Ď' => 'D',  'Ð' => 'D',
            'Ē' => 'E',  'Ĕ' => 'E',  'Ė' => 'E',  'Ę' => 'E',  'Ě' => 'E',
            'Ĝ' => 'G',  'Ğ' => 'G',  'Ġ' => 'G',  'Ģ' => 'G',
            'Ħ' => 'H',  'Ĥ' => 'H',
            'Ĩ' => 'I',  'Ī' => 'I',  'Ĭ' => 'I',  'Į' => 'I',  'İ' => 'I',
            'Ĵ' => 'J',
            'Ķ' => 'K',
            'Ĺ' => 'L',  'Ļ' => 'L',  'Ľ' => 'L',  'Ŀ' => 'L',  'Ł' => 'L',
            'Ń' => 'N',  'Ņ' => 'N',  'Ň' => 'N',  'Ŋ' => 'N',
            'Ō' => 'O',  'Ŏ' => 'O',  'Ő' => 'O',  'Œ' => 'OE',
            'Ŕ' => 'R',  'Ŗ' => 'R',  'Ř' => 'R',
            'Ś' => 'S',  'Ŝ' => 'S',  'Ş' => 'S',  'Š' => 'S',
            'Ţ' => 'T',  'Ť' => 'T',  'Ŧ' => 'T',
            'Ũ' => 'U',  'Ū' => 'U',  'Ŭ' => 'U',  'Ů' => 'U',  'Ű' => 'U',  'Ų' => 'U',
            'Ŵ' => 'W',
            'Ŷ' => 'Y',  'Ÿ' => 'Y',
            'Ź' => 'Z',  'Ż' => 'Z',  'Ž' => 'Z',
            'ß' => 'SS',
        );
    }

    /**
     * Транслитерация текста (кириллица + диакритика → чистая латиница).
     * По умолчанию НЕ используется в парсере — доступен для будущего использования.
     *
     * @param string $text — исходный текст
     * @return string — транслитерированный текст (верхний регистр)
     */
    public static function transliterate($text)
    {
        $text = mb_strtoupper($text, 'UTF-8');
        $map = array_merge(self::getTransliterationMap(), self::getMultinationalTransliterationMap());

        $result = '';
        $len = mb_strlen($text, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');
            if (isset($map[$char])) {
                $result .= $map[$char];
            } else {
                $result .= $char;
            }
        }
        return $result;
    }
}
