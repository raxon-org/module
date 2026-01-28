<?php
/**
 * @author          Remco van der Velde
 * @since           2026-01-28
 * @copyright       (c) Remco van der Velde
 * @license         MIT
 * @version         1.0
 */
namespace Module;

use ReflectionObject;

use Defuse\Crypto\Key;

use Defuse\Crypto\Exception\BadFormatException;
use Defuse\Crypto\Exception\EnvironmentIsBrokenException;

use Error;
use Exception;
use ReflectionException;

use Exception\UrlEmptyException;
use Exception\ObjectException;
use Exception\FileWriteException;

class Core
{

    const EXCEPTION_MERGE_ARRAY_OBJECT = 'Cannot merge an array with an object.';
    const EXCEPTION_OBJECT_OUTPUT = 'Unknown output in object.';

    const ATTRIBUTE_EXPLODE = [
        '.'
    ];

    const OBJECT_ARRAY = 'array';
    const OBJECT_OBJECT = 'object';
    const OBJECT_JSON = 'json';
    const OBJECT_JSON_DATA = 'json-data';
    const OBJECT_JSON_LINE = 'json-line';

    const OBJECT_TYPE_ROOT = 'root';
    const OBJECT_TYPE_CHILD = 'child';

    const SHELL_DETACHED = 'detached';
    const SHELL_NORMAL = 'normal';
    const SHELL_PROCESS = 'process';

    const OUTPUT_MODE_IMPLICIT = 'implicit';
    const OUTPUT_MODE_EXPLICIT = 'explicit';
    const OUTPUT_MODE_DEFAULT = Core::OUTPUT_MODE_EXPLICIT;

    const LOCAL = 'local';

    const OUTPUT_MODE = [
        Core::OUTPUT_MODE_IMPLICIT,
        Core::OUTPUT_MODE_EXPLICIT,
    ];

    const MODE_INTERACTIVE = Core::OUTPUT_MODE_IMPLICIT;
    const MODE_PASSIVE = Core::OUTPUT_MODE_EXPLICIT;

    const STREAM = 'stream';
    const FILE = 'file';
    const PROMPT = 'prompt';
    const JSON = 'json';
    const JSON_DATA = 'json-data';
    const JSON_LINE = 'json-line';
    const OBJECT = 'object';
    const ARRAY = 'array';

    const TRANSFER = 'transfer';
    const FINALIZE = 'finalize';

    /**
     * @throws UrlEmptyException
     */
    public static function redirect(string $url = ''): void
    {
        if (empty($url)) {
            throw new UrlEmptyException('url is empty...');
        }
        header('Location: ' . $url, true, 302);
        exit;
    }

    public static function is_array_nested(mixed $array = []): bool
    {
        $array = (array)$array;
        foreach ($array as $value) {
            if (is_array($value)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @throws Exception
     */
    public static function array_partition(array $array=[], int $size=1, bool $preserve_keys=false, bool $count=false): array
    {
        $array = (array) $array;
        $size = (int) $size;
        if($count !== false){
            $count = (int) $count;
        }
        if($size < 1){
            throw new Exception('Size must be greater than 0');
        }
        if($count !== false){
            return array_chunk($array, ceil($count / $size), $preserve_keys);
        }
        return array_chunk($array, ceil(count($array) / $size), $preserve_keys);
    }

    public static function array_bestmatch_list(array $array=[], string $search='', bool $with_score=false): bool | array
    {
        if(empty($array)){
            return false;
        }
        $bestmatch = [];
        $search = substr($search, 0, 255);
        foreach($array as $nr => $record){
            $match = substr($record, 0, 255);
            $levensthein = levenshtein($search, $match);
            $length = strlen($match);
            $score = $length - $levensthein / $length;
            $bestmatch[$score][$nr] = $match;
        }
        krsort($bestmatch, SORT_NATURAL);
        $array = [];
        foreach($bestmatch as $score => $list){
            foreach($list as $key => $match){
                if($with_score){
                    $array[$key] = [
                        'string' => $match,
                        'score' => $score
                    ];
                } else {
                    $array[$key] = $match;
                }
            }
        }
        return $array;
    }

    public static function array_bestmatch_key(array $array=[], string $search=''): bool | int | string | null
    {
        if(empty($array)){
            return false;
        }
        $array = Core::array_bestmatch_list($array, $search, false);
        reset($array);
        return key($array);
    }

    public static function array_bestmatch(array $array=[], string $search='', bool $with_score=false){
        if(empty($array)){
            return false;
        }
        $array = Core::array_bestmatch_list($array, $search, $with_score);
        return reset($array);
    }

    public static function array_object(array $array = []): object
    {
        $object = (object) [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $object->{$key} = Core::array_object($value);
            } else {
                $object->{$key} = $value;
            }
        }
        return $object;
    }

    /**
     * @throws ReflectionException
     */
    public static function object_array(object|null $object = null): array
    {
        $list = [];
        if ($object === null) {
            return $list;
        }
        $reflection = new ReflectionObject($object);
        do {
            foreach ($reflection->getProperties() as $property) {
                $property->setAccessible(true);
                if (!array_key_exists($property->name, $list)) {
                    $list[$property->name] = $property->getValue($object);
                }
            }
        } while ($reflection = $reflection->getParentClass());
        return $list;
    }

    public static function explode_multi(array $delimiter = [], string $string = '', array|string $limit = []): array
    {
        $result = array();
        if (!is_array($limit)) {
            $limit = explode(',', $limit);
            $value = reset($limit);
            if (count($delimiter) > count($limit)) {
                for ($i = count($limit); $i < count($delimiter); $i++) {
                    $limit[$i] = $value;
                }
            }
        }
        foreach ($delimiter as $nr => $delim) {
            if (isset($limit[$nr])) {
                $tmp = explode($delim, $string, $limit[$nr]);
            } else {
                $tmp = explode($delim, $string);
            }
            if (count($tmp) == 1) {
                continue;
            }
            foreach ($tmp as $tmp_value) {
                $result[] = $tmp_value;
            }
        }
        if (empty($result)) {
            $result[] = $string;
        }
        return $result;
    }

    /**
     * @throws ObjectException
     */
    public static function object(mixed $input = '', string|null $output = null, string|null $type = null): mixed
    {
        if ($output === null) {
            $output = Core::OBJECT_OBJECT;
        }
        if ($type === null) {
            $type = Core::OBJECT_TYPE_ROOT;
        }
        if (is_bool($input)) {
            if ($output == Core::OBJECT_OBJECT || $output == Core::OBJECT_JSON) {
                $data = (object) [];
                if (empty($input)) {
                    $data->false = false;
                } else {
                    $data->true = true;
                }
                if ($output == Core::OBJECT_JSON) {
                    $data = json_encode($data);
                }
                return $data;
            } elseif ($output == Core::OBJECT_ARRAY) {
                return array($input);
            } else {
                throw new ObjectException(Core::EXCEPTION_OBJECT_OUTPUT);
            }
        } elseif (is_null($input)) {
            if ($output == Core::OBJECT_OBJECT) {
                return (object) [];
            } elseif ($output == Core::OBJECT_ARRAY) {
                return array();
            } elseif ($output == Core::OBJECT_JSON) {
                return '{}';
            }
        } elseif (is_object($input) && $output === Core::OBJECT_JSON) {
            $json = json_encode($input, JSON_PRETTY_PRINT);
            if (json_last_error()) {
                throw new ObjectException(json_last_error_msg());
            }
            return $json;
        } elseif (is_array($input) && $output === Core::OBJECT_OBJECT) {
            return Core::array_object($input);
        } elseif (is_array($input) && $output === Core::OBJECT_JSON) {
            $json = json_encode($input, JSON_PRETTY_PRINT);
            if (json_last_error()) {
                throw new ObjectException(json_last_error_msg());
            }
            return $json;
        } elseif (is_string($input)) {
            $input = trim($input);
            $input = preg_replace('/[[:cntrl:]]/', '', $input);
            if ($output == Core::OBJECT_OBJECT) {
                if (substr($input, 0, 1) == '{' && substr($input, -1, 1) == '}') {
                    try {
                        if(function_exists('simd_json_decode')){
                            $json = @simd_json_decode($input);
                        } else {
                            throw new Exception('simd_json_decode failed');
                        }
                    }
                    catch (Exception $exception){
                        $json = json_decode($input);
                        if (json_last_error()) {
                            throw new ObjectException(json_last_error_msg() . json_last_error());
                        }
                    }
                    return $json;
                } elseif (substr($input, 0, 1) == '[' && substr($input, -1, 1) == ']') {                    
                    try {
                        if(function_exists('simd_json_decode')){
                            $json = @simd_json_decode($input);
                        } else {
                            throw new Exception('simd_json_decode failed');
                        }
                    }
                    catch (Exception $exception){
                        $json = json_decode($input);
                        if (json_last_error()) {
                            trace();
                            d($input);
                            d($json);
                            ddd(json_last_error_msg() . json_last_error());
                            throw new ObjectException(json_last_error_msg() . json_last_error());
                        }
                    }
                    return $json;
                }
            } elseif (stristr($output, Core::OBJECT_JSON) !== false) {
                if (substr($input, 0, 1) == '{' && substr($input, -1, 1) == '}') {
                    try {
                        if(function_exists('simd_json_decode')){
                            $input = @simd_json_decode($input);
                        } else {
                            throw new Exception('simd_json_decode failed');
                        }
                    }
                    catch (Exception $exception){
                        $input_decode = json_decode($input);
                        if (json_last_error()) {
                            throw new ObjectException(json_last_error_msg() . PHP_EOL . (string) $input . PHP_EOL);
                        }
                        $input = $input_decode;
                    }
                }
            } elseif ($output == Core::OBJECT_ARRAY) {
                if (substr($input, 0, 1) == '{' && substr($input, -1, 1) == '}') {
                    try {
                        if(function_exists('simd_json_decode')){
                            return simd_json_decode($input, true);
                        } else {
                            throw new Exception('simd_json_decode failed');
                        }
                    }
                    catch (Exception $exception){
                        return json_decode($input, true);
                    }
                } elseif (substr($input, 0, 1) == '[' && substr($input, -1, 1) == ']') {
                    try {
                        if(function_exists('simd_json_decode')){
                            return simd_json_decode($input, true);
                        } else {
                            throw new Exception('simd_json_decode failed');
                        }
                    }
                    catch (Exception $exception){
                        return json_decode($input, true);
                    }
                }
            }
        }
        if (stristr($output, Core::OBJECT_JSON) !== false && stristr($output, 'data') !== false) {
            $data = str_replace('"', '&quot;', json_encode($input));
        }
        elseif (stristr($output, Core::OBJECT_JSON) !== false && stristr($output, 'line') !== false) {
            $data = json_encode($input);
        }
        elseif($output === Core::TRANSFER){
            return str_replace(['\\', '\''],['\\\\', '\\\''] , json_encode($input));
        }
        elseif($output === Core::FINALIZE){
            return json_decode($input);
        } else {
            $data = json_encode($input, JSON_PRETTY_PRINT);
        }
        if ($output == Core::OBJECT_OBJECT) {
            try {
                if(function_exists('simd_json_decode')){
                    return simd_json_decode($data);
                } else {
                    throw new Exception('simd_json_decode failed');
                }
            }
            catch (Exception $exception){
                return json_decode($data);
            }
        } elseif (stristr($output, Core::OBJECT_JSON) !== false) {
            if ($type == Core::OBJECT_TYPE_CHILD) {
                return substr($data, 1, -1);
            } else {
                return $data;
            }
        } elseif ($output == Core::OBJECT_ARRAY) {
            try {
                if(function_exists('simd_json_decode')){
                    return simd_json_decode($data, true);
                } else {
                    throw new Exception('simd_json_decode failed');
                }
            }
            catch (Exception $exception){
                return json_decode($data, true);
            }
        } else {
            if(is_string($output)){
                throw new ObjectException('Unknown output in object: ' . $output);
            }
            throw new ObjectException(Core::EXCEPTION_OBJECT_OUTPUT);
        }
    }

    public static function object_delete(mixed $attributeList = [], mixed $object = null, mixed $parent = null, int|string|null $key = null): bool
    {
        if (is_scalar($attributeList)) {
            $explode = explode('.', $attributeList, 3);
            if(is_object($object)){
                if(
                    property_exists($object, $attributeList)
                ) {
                    unset($object->{$attributeList});
                    return true;
                }
                elseif(
                    array_key_exists(2, $explode) &&
                    isset($object->{$explode[0]})
                ){
                    return Core::object_delete($explode[1] . '.' . $explode[2], $object->{$explode[0]}, $object, $explode[0]);
                }
                elseif(
                    array_key_exists(1, $explode) &&
                    isset($object->{$explode[0]})
                ){
                    return Core::object_delete($explode[1], $object->{$explode[0]}, $object, $explode[0]);
                }
            }
            elseif(is_array($object)) {
                if(
                    array_key_exists($attributeList, $object)
                ){
                    unset($object[$attributeList]);
                    return true;
                }
                elseif(
                    array_key_exists(2, $explode) &&
                    isset($object[$explode[0]])
                ){
                    return Core::object_delete($explode[1] . '.' . $explode[2], $object[$explode[0]], $object, $explode[0]);
                }
                elseif(
                    array_key_exists(1, $explode) &&
                    isset($object[$explode[0]])
                ){
                    return Core::object_delete($explode[1], $object[$explode[0]], $object, $explode[0]);
                }
            }
            $attributeList = Core::explode_multi(Core::ATTRIBUTE_EXPLODE, (string)$attributeList);
        }
        if (is_array($attributeList)) {
            $attributeList = Core::object_horizontal($attributeList);
        }
        if (!empty($attributeList) && is_object($attributeList)) {
            foreach ($attributeList as $key => $attribute) {
                if(is_object($object)){
                    if (isset($object->{$key})) {
                        return Core::object_delete($attribute, $object->{$key}, $object, $key);
                    } else {
                        unset($object->{$key}); //to delete nulls
                        return false;
                    }
                } elseif(is_array($object)) {
                    if (isset($object[$key])) {
                        return Core::object_delete($attribute, $object[$key], $object, $key);
                    } else {
                        unset($object[$key]); //to delete nulls
                        return false;
                    }
                }
            }
        } else {
            if(!empty($parent)){
                if(is_object($parent)){
                    unset($parent->{$key});    //unset $object won't delete it from the first object (parent) given
                }
                elseif(is_array($parent)){
                    unset($parent[$key]);
                }
            }
            return true;
        }
        return false;
    }

    public static function object_has_property(mixed $attributeList = [], array|object|null $object = null): bool
    {
        if(is_string($attributeList) || is_numeric($attributeList)) {
            $attributeList = Core::explode_multi(Core::ATTRIBUTE_EXPLODE, (string) $attributeList);
        }
        if(is_array($object)){
            $properties = [];
            if(
                count($attributeList) === 1 &&
                strpos($attributeList[0], '.') === false
            ){
                if(array_key_exists($attributeList[0], $object)){
                    return true;
                } else {
                    return false;
                }
            }
            while(!empty($attributeList)){
                $properties[] = implode('.', $attributeList);
                array_pop($attributeList);
                if(empty($attributeList)){
                    break;
                }
            }
            $need_next_change = false;
            $ready = false;
            while(!empty($properties)){
                foreach($properties as $nr => $property){
                    if(strpos($property, '.') !== false){
                        if(
                            is_array($object) &&
                            array_key_exists($property, $object)
                        ){
                            $object = $object[$property];
                            if($need_next_change){
                                $need_next_change = false;
                            }
                            $property_count = count($properties);
                            for($i = $nr; $i < $property_count; $i++){
                                unset($properties[$i]);
                            }
                            if(empty($properties)){
                                $ready = true;
                            } else {
                                foreach($properties as $nr => $temp_property){
                                    $properties[$nr] = str_replace($property . '.', '', $temp_property);
                                }
                            }
                        }
                        elseif(
                            is_object($object) &&
                            property_exists($object, $property)
                        ){
                            $object = $object->{$property};
                            if($need_next_change){
                                $need_next_change = false;
                            }
                            $property_count = count($properties);
                            for($i = $nr; $i < $property_count; $i++){
                                unset($properties[$i]);
                            }
                            if(empty($properties)){
                                $ready = true;
                            } else {
                                foreach($properties as $nr => $temp_property){
                                    $properties[$nr] = str_replace($property . '.', '', $temp_property);
                                }
                            }
                        }
                    }
                }
                if(
                    count($properties) === 1 &&
                    strpos($property, '.') === false
                ){
                    if(
                        is_array($object) &&
                        array_key_exists($property, $object) &&
                        $ready
                    ){
                        return true;
                    }
                    elseif(
                        is_object($object) &&
                        property_exists($object, $property)
                    ){
                        return true;
                    } else {
                        return false;
                    }
                }
                if(empty($properties)){
                    if($ready){
                        return true;
                    }
                    return false;
                }
                foreach($properties as $nr => $property){
                    $attributeList = explode('.', $property);
                    if(array_key_exists(1, $attributeList)){
                        $shift = array_shift($attributeList);
                        if(is_array($object)){
                            if(array_key_exists($shift, $object)){
                                $object = $object[$shift];
                                $ready = false;
                                foreach($attributeList as $attributeList_nr => $attribute){
                                    if(
                                        is_array($object) &&
                                        array_key_exists($attribute, $object)
                                    ){
                                        $object = $object[$attribute];
                                        unset($attributeList[$attributeList_nr]);
                                        $need_next_change = false;
                                        $ready = true;
                                    }
                                    elseif(
                                        is_object($object) &&
                                        property_exists($object, $attribute)
                                    ){
                                        $object = $object->{$attribute};
                                        unset($attributeList[$attributeList_nr]);
                                        $need_next_change = false;
                                        $ready = true;
                                    }
                                    elseif($need_next_change === false){
                                        $need_next_change = true;
                                        $ready = false;
                                        break;
                                    }
                                    elseif($need_next_change === true){
                                        return false;
                                    }
                                }
                                if(empty($attributeList)){
                                    unset($properties[$nr]);
                                    if($ready){
                                        return true;
                                    }
                                } else {
                                    $properties[$nr] = implode('.', $attributeList);
                                }
                            } else {
                                return false;
                            }
                        }
                        elseif(is_object($object)){
                            if(property_exists($object, $shift)){
                                $object = $object->{$shift};
                                $ready = false;
                                foreach($attributeList as $attributeList_nr => $attribute){
                                    if(
                                        is_array($object) &&
                                        array_key_exists($attribute, $object)
                                    ){
                                        $object = $object[$attribute];
                                        unset($attributeList[$attributeList_nr]);
                                        $need_next_change = false;
                                        $ready = true;
                                    }
                                    elseif(
                                        is_object($object) &&
                                        property_exists($object, $attribute)
                                    ){
                                        $object = $object->{$attribute};
                                        unset($attributeList[$attributeList_nr]);
                                        $need_next_change = false;
                                        $ready = true;
                                    }
                                    elseif($need_next_change === false){
                                        $need_next_change = true;
                                        $ready = false;
                                        break;
                                    }
                                    elseif($need_next_change === true){
                                        return false;
                                    }
                                }
                                if(empty($attributeList)){
                                    unset($properties[$nr]);
                                    if($ready){
                                        return true;
                                    }
                                } else {
                                    $properties[$nr] = implode('.', $attributeList);
                                }
                            } else {
                                return false;
                            }
                        } else {
                            if(empty($attributeList)){
                                unset($properties[$nr]);
                            } else {
                                $properties[$nr] = implode('.', $attributeList);
                            }
                        }
                    } else {
                        unset($properties[$nr]);
                    }
                }
                if(empty($properties)){
                    if($ready){
                        return true;
                    }
                    return false;
                }
            }
            return false;
        }
        elseif(is_object($object)){
            $properties = [];
            if(
                count($attributeList) === 1 &&
                strpos($attributeList[0], '.') === false
            ){
                if(property_exists($object, $attributeList[0])){
                    return true;
                } else {
                    return false;
                }
            }
            while(!empty($attributeList)){
                $properties[] = implode('.', $attributeList);
                array_pop($attributeList);
                if(empty($attributeList)){
                    break;
                }
            }
            $need_next_change = false;
            $ready = false;
            while(!empty($properties)){
                foreach($properties as $nr => $property){
                    if(strpos($property, '.') !== false){
                        if(
                            is_object($object) &&
                            property_exists($object, $property)
                        ){
                            $object = $object->{$property};
                            if($need_next_change){
                                $need_next_change = false;
                            }
                            $property_count = count($properties);
                            for($i = $nr; $i < $property_count; $i++){
                                unset($properties[$i]);
                            }
                            if(empty($properties)){
                                $ready = true;
                            }
                        }
                        if(
                            is_array($object) &&
                            array_key_exists($property, $object)
                        ){
                            $object = $object[$property];
                            if($need_next_change){
                                $need_next_change = false;
                            }
                            $property_count = count($properties);
                            for($i = $nr; $i < $property_count; $i++){
                                unset($properties[$i]);
                            }
                            if(empty($properties)){
                                $ready = true;
                            }
                        }
                    }
                }
                if(
                    count($properties) === 1 &&
                    strpos($property, '.') === false
                ){
                    if(
                        is_array($object) &&
                        array_key_exists($property, $object) &&
                        $ready
                    ){
                        return true;
                    }
                    if(
                        is_object($object) &&
                        property_exists($object, $property) &&
                        $ready
                    ){
                        return true;
                    } else {
                        return false;
                    }
                }
                if(empty($properties)){
                    if($ready){
                        return true;
                    }
                    return false;
                }
                foreach($properties as $nr => $property){
                    $attributeList = explode('.', $property);
                    if(array_key_exists(1, $attributeList)){
                        $shift = array_shift($attributeList);
                        if(is_array($object)){
                            if(array_key_exists($shift, $object)){
                                $object = $object[$shift];
                                $ready = false;
                                foreach($attributeList as $attributeList_nr => $attribute){
                                    if(
                                        is_array($object) &&
                                        array_key_exists($attribute, $object)
                                    ){
                                        $object = $object[$attribute];
                                        unset($attributeList[$attributeList_nr]);
                                        $need_next_change = false;
                                        $ready = true;
                                    }
                                    elseif(
                                        is_object($object) &&
                                        property_exists($object, $attribute)
                                    ){
                                        $object = $object->{$attribute};
                                        unset($attributeList[$attributeList_nr]);
                                        $need_next_change = false;
                                        $ready = true;
                                    }
                                    elseif($need_next_change === false){
                                        $need_next_change = true;
                                        $ready = false;
                                    }
                                    elseif($need_next_change === true){
                                        return false;
                                    }
                                }
                                if(empty($attributeList)){
                                    unset($properties[$nr]);
                                    if($ready){
                                        return true;
                                    }
                                } else {
                                    $properties[$nr] = implode('.', $attributeList);
                                }
                            } else {
                                return false;
                            }
                        }
                        elseif(is_object($object)){
                            if(property_exists($object, $shift)){
                                $object = $object->{$shift};
                                $ready = false;
                                foreach($attributeList as $attributeList_nr => $attribute){
                                    if(
                                        is_array($object) &&
                                        array_key_exists($attribute, $object)
                                    ){
                                        $object = $object[$attribute];
                                        unset($attributeList[$attributeList_nr]);
                                        $need_next_change = false;
                                        $ready = true;
                                    }
                                    elseif(
                                        is_object($object) &&
                                        property_exists($object, $attribute)
                                    ){
                                        $object = $object->{$attribute};
                                        unset($attributeList[$attributeList_nr]);
                                        $need_next_change = false;
                                        $ready = true;
                                    }
                                    elseif($need_next_change === false){
                                        $need_next_change = true;
                                        $ready = false;
                                    }
                                    elseif($need_next_change === true){
                                        return false;
                                    }
                                }
                                if(empty($attributeList)){
                                    unset($properties[$nr]);
                                    if($ready){
                                        return true;
                                    }
                                } else {
                                    $properties[$nr] = implode('.', $attributeList);
                                }
                            } else {
                                return false;
                            }
                        } else {
                            if(empty($attributeList)){
                                unset($properties[$nr]);
                            } else {
                                $properties[$nr] = implode('.', $attributeList);
                            }
                        }
                    } else {
                        unset($properties[$nr]);
                    }
                }
                if(empty($properties)){
                    if($ready){
                        return true;
                    }
                    return false;
                }
            }
            return false;
        }
        return false;
    }

    public static function object_has(mixed $attributeList = [], array|object|null $object = null): bool
    {
        if (
            is_object($object) &&
            Core::object_is_empty($object)
        ) {
            if (empty($attributeList)) {
                return true;
            }
            return false;
        }
        elseif(
            is_array($object) &&
            empty($object)
        ){
            if (empty($attributeList)) {
                return true;
            }
            return false;
        }
        $get = Core::object_get($attributeList, $object);
        if($get !== null){
            return true;
        }
        return false;
    }

    public static function object_get(mixed $attributeList = [], array|object|null $object = null, bool $is_debug=false): mixed
    {
        if(is_string($attributeList) || is_numeric($attributeList)) {
            $attributeList = Core::explode_multi(Core::ATTRIBUTE_EXPLODE, (string) $attributeList);
        }

        if(is_array($object)){
            $properties = [];
            if(
                count($attributeList) === 1 &&
                strpos($attributeList[0], '.') === false
            ){
                if(array_key_exists($attributeList[0], $object)){
                    return $object[$attributeList[0]];
                } else {
                    return null;
                }
            }
            while(!empty($attributeList)){
                $properties[] = implode('.', $attributeList);
                array_pop($attributeList);
                if(empty($attributeList)){
                    break;
                }
            }
            $need_next_change = false;
            $ready = false;
            while(!empty($properties)){
                foreach($properties as $nr => $property){
                    if(strpos($property, '.') !== false){
                        if(
                            is_array($object) &&
                            array_key_exists($property, $object)
                        ){
                            $object = $object[$property];
                            if($need_next_change){
                                $need_next_change = false;
                            }
                            $property_count = count($properties);
                            for($i = $nr; $i < $property_count; $i++){
                                unset($properties[$i]);
                            }
                            if(empty($properties)){
                                $ready = true;
                            } else {
                                foreach($properties as $nr => $temp_property){
                                    $properties[$nr] = str_replace($property . '.', '', $temp_property);
                                }
                            }
                        }
                        elseif(
                            is_object($object) &&
                            property_exists($object, $property)
                        ){
                            $object = $object->{$property};
                            if($need_next_change){
                                $need_next_change = false;
                            }
                            for($i = $nr; $i < count($properties); $i++){
                                unset($properties[$i]);
                            }
                            if(empty($properties)){
                                $ready = true;
                            } else {
                                foreach($properties as $nr => $temp_property){
                                    $properties[$nr] = str_replace($property . '.', '', $temp_property);
                                }
                            }
                        }
                    }
                }
                if(
                    count($properties) === 1 &&
                    strpos($property, '.') === false
                ){
                    if(
                        is_object($object) &&
                        property_exists($object, $property) &&
                        $ready
                    ){
                        return $object->{$property};
                    }
                    elseif(
                        is_array($object) &&
                        array_key_exists($property, $object) &&
                        $ready
                    ){
                        return $object[$property];
                    } else {
                        return null;
                    }
                }
                if(empty($properties)){
                    if($ready){
                        return $object;
                    }
                    return null;
                }
                foreach($properties as $nr => $property){
                    $attributeList = explode('.', $property);
                    if(array_key_exists(1, $attributeList)){
                        $shift = array_shift($attributeList);
                        if(is_array($object)){
                            if(array_key_exists($shift, $object)){
                                $object = $object[$shift];
                                $ready = false;
                                foreach($attributeList as $attributeList_nr => $attribute){
                                    if(
                                        is_array($object) &&
                                        array_key_exists($attribute, $object)
                                    ){
                                        $object = $object[$attribute];
                                        unset($attributeList[$attributeList_nr]);
                                        $need_next_change = false;
                                        $ready = true;
                                    }
                                    elseif(
                                        is_object($object) &&
                                        property_exists($object, $attribute)
                                    ){
                                        $object = $object->{$attribute};
                                        unset($attributeList[$attributeList_nr]);
                                        $need_next_change = false;
                                        $ready = true;
                                    }
                                    elseif(is_null($object)){ //added @2024-07-28
                                        return null;
                                    }
                                    elseif(is_array($object)){ //added @2024-07-28
                                        return null;
                                    }
                                    elseif(is_object($object)){ //added @2024-07-28
                                        return null;
                                    }
                                    elseif($need_next_change === false){
                                        $need_next_change = true;
                                        $ready = false;
                                        break;
                                    }
                                    elseif($need_next_change === true){
                                        return null;
                                    }
                                }
                                if(empty($attributeList)){
                                    unset($properties[$nr]);
                                    if($ready){
                                        return $object;
                                    }
                                } else {
                                    $properties[$nr] = implode('.', $attributeList);
                                }
                            } else {
                                return null;
                            }
                        }
                        elseif(is_object($object)){
                            if(property_exists($object, $shift)){
                                $object = $object->{$shift};
                                $ready = false;
                                foreach($attributeList as $attributeList_nr => $attribute){
                                    if(
                                        is_array($object) &&
                                        array_key_exists($attribute, $object)
                                    ){
                                        $object = $object[$attribute];
                                        unset($attributeList[$attributeList_nr]);
                                        $need_next_change = false;
                                        $ready = true;
                                    }
                                    elseif(
                                        is_object($object) &&
                                        property_exists($object, $attribute)
                                    ){
                                        $object = $object->{$attribute};
                                        unset($attributeList[$attributeList_nr]);
                                        $need_next_change = false;
                                        $ready = true;
                                    }
                                    elseif(is_null($object)){ //added @2024-07-28
                                        return null;
                                    }
                                    elseif(is_array($object)){ //added @2024-07-28
                                        return null;
                                    }
                                    elseif(is_object($object)){ //added @2024-07-28
                                        return null;
                                    }
                                    elseif($need_next_change === false){
                                        $need_next_change = true;
                                        $ready = false;
                                        break;
                                    }
                                    elseif($need_next_change === true){
                                        return null;
                                    }
                                }
                                if(empty($attributeList)){
                                    unset($properties[$nr]);
                                    if($ready){
                                        return $object;
                                    }
                                } else {
                                    $properties[$nr] = implode('.', $attributeList);
                                }
                            } else {
                                return null;
                            }
                        } else {
                            if(empty($attributeList)){
                                unset($properties[$nr]);
                            } else {
                                $properties[$nr] = implode('.', $attributeList);
                            }
                        }
                    } else {
                        unset($properties[$nr]);
                    }
                }
                if(empty($properties)){
                    if($ready){
                        return $object;
                    }
                    return null;
                }
            }
            return null;
        }
        elseif(is_object($object)){
            $properties = [];
            if(
                count($attributeList) === 1 &&
                strpos($attributeList[0], '.') === false
            ){
                if(property_exists($object, $attributeList[0])){
                    return $object->{$attributeList[0]};
                } else {
                    return null;
                }
            }
            while(!empty($attributeList)){
                $properties[] = implode('.', $attributeList);
                array_pop($attributeList);
                if(empty($attributeList)){
                    break;
                }
            }
            $need_next_change = false;
            $ready = false;
            while(!empty($properties)){
                foreach($properties as $nr => $property){
                    if(strpos($property, '.') !== false){
                        if(
                            is_object($object) &&
                            property_exists($object, $property)
                        ){
                            $object = $object->{$property};
                            if($need_next_change){
                                $need_next_change = false;
                            }
                            $property_count = count($properties);
                            for($i = $nr; $i < $property_count; $i++){
                                unset($properties[$i]);
                            }
                            if(empty($properties)){
                                $ready = true;
                            }
                        }
                        if(
                            is_array($object) &&
                            array_key_exists($property, $object)
                        ){
                            $object = $object[$property];
                            if($need_next_change){
                                $need_next_change = false;
                            }
                            $property_count = count($properties);
                            for($i = $nr; $i < $property_count; $i++){
                                unset($properties[$i]);
                            }
                            if(empty($properties)){
                                $ready = true;
                            }
                        }
                    }
                }
                if(
                    count($properties) === 1 &&
                    strpos($property, '.') === false
                ){
                    if(
                        is_object($object) &&
                        property_exists($object, $property) &&
                        $ready
                    ){
                        return $object->{$property};
                    }
                    if(
                        is_array($object) &&
                        array_key_exists($property, $object) &&
                        $ready
                    ){
                        return $object[$property];
                    } else {
                        return null;
                    }
                }
                if(empty($properties)){
                    if($ready){
                        return $object;
                    }
                    return null;
                }
                foreach($properties as $nr => $property){
                    $attributeList = explode('.', $property);
                    if(array_key_exists(1, $attributeList)){
                        $shift = array_shift($attributeList);
                        if(is_array($object)){
                            if(array_key_exists($shift, $object)){
                                $object = $object[$shift];
                                $ready = false;
                                foreach($attributeList as $attributeList_nr => $attribute){
                                    if(
                                        is_array($object) &&
                                        array_key_exists($attribute, $object)
                                    ){
                                        $object = $object[$attribute];
                                        unset($attributeList[$attributeList_nr]);
                                        $need_next_change = false;
                                        $ready = true;
                                    }
                                    elseif(
                                        is_object($object) &&
                                        property_exists($object, $attribute)
                                    ){
                                        $object = $object->{$attribute};
                                        unset($attributeList[$attributeList_nr]);
                                        $need_next_change = false;
                                        $ready = true;
                                    }
                                    elseif(is_null($object)){ //added @2024-07-28
                                        return null;
                                    }
                                    elseif(is_array($object)){ //added @2024-07-28
                                        return null;
                                    }
                                    elseif(is_object($object)){ //added @2024-07-28
                                        return null;
                                    }
                                    elseif($need_next_change === false){
                                        $need_next_change = true;
                                        $ready = false;
                                    }
                                    elseif($need_next_change === true){
                                        return null;
                                    }
                                }
                                if(empty($attributeList)){
                                    unset($properties[$nr]);
                                    if($ready){
                                        return $object;
                                    }
                                } else {
                                    $properties[$nr] = implode('.', $attributeList);
                                }
                            } else {
                                return null;
                            }
                        }
                        elseif(is_object($object)){
                            if(property_exists($object, $shift)){
                                $object = $object->{$shift};
                                $ready = false;
                                /*
                                if($is_debug === true){
                                    d($object);
                                    ddd($attributeList);
                                }
                                */
                                foreach($attributeList as $attributeList_nr => $attribute){
                                    if(
                                        is_array($object) &&
                                        array_key_exists($attribute, $object)
                                    ){
                                        $object = $object[$attribute];
                                        unset($attributeList[$attributeList_nr]);
                                        $need_next_change = false;
                                        $ready = true;
                                    }
                                    elseif(
                                        is_object($object) &&
                                        property_exists($object, $attribute)
                                    ){
                                        $object = $object->{$attribute};
                                        unset($attributeList[$attributeList_nr]);
                                        $need_next_change = false;
                                        $ready = true;
                                    }
                                    elseif(is_null($object)){ //added @2024-07-28
                                        return null;
                                    }
                                    elseif(is_array($object)){ //added @2024-07-28
                                        return null;
                                    }
                                    elseif(is_object($object)){ //added @2024-07-28
                                        return null;
                                    }
                                    elseif($need_next_change === false){
                                        $need_next_change = true;
                                        $ready = false;
                                    }
                                    elseif($need_next_change === true){
                                        return null;
                                    }
                                }
                                if(empty($attributeList)){
                                    unset($properties[$nr]);
                                    if($ready){
                                        return $object;
                                    }
                                } else {
                                    $properties[$nr] = implode('.', $attributeList);
                                }
                            } else {
                                return null;
                            }
                        } else {
                            if(empty($attributeList)){
                                unset($properties[$nr]);
                            } else {
                                $properties[$nr] = implode('.', $attributeList);
                            }
                        }
                    } else {
                        unset($properties[$nr]);
                    }
                }
                if(empty($properties)){
                    if($ready){
                        return $object;
                    }
                    return null;
                }
            }
            return null;
        }
        return null;
    }

    private static function object_get_nested(mixed $attributeList='', array|object|null $object=null, string $key='', bool $is_debug=false): mixed
    {
        $is_collect = [];
        $is_collect[] = $key;
        if(empty($attributeList)){
            return null;
        }
        $keys = [];
        $keys_attribute_list = [];
        while(!empty($attributeList)){
            foreach($attributeList as $key_attribute => $value_attribute) {
                $is_collect[] = $key_attribute;
                $key_collect = implode('.', $is_collect);
                $keys[] = $key_collect;
                $keys_attribute_list[] = $attributeList->{$key_attribute};
                $attributeList = $value_attribute;
            }
        }
        krsort($keys, SORT_NATURAL);
        krsort($keys_attribute_list, SORT_NATURAL);
        foreach($keys as $nr => $key_collect){
            if (isset($object->{$key_collect})) {
                if(null === $keys_attribute_list[$nr]){
                    return $object->{$key_collect};
                } else {
                    return Core::object_get($keys_attribute_list[$nr], $object->{$key_collect}, $is_debug);
                }
            }
            elseif(is_array($object)){
                if(array_key_exists($key_collect, $object)){
                    if(null === $keys_attribute_list[$nr]){
                        return $object[$key_collect];
                    } else {
                        return Core::object_get($keys_attribute_list[$nr], $object[$key_collect], $is_debug);
                    }
                } else {
                    if(null === $keys_attribute_list[$nr]){
                        continue;
                    } else {
                        return Core::object_get_nested($keys_attribute_list[$nr], $object, $key_collect, $is_debug);
                    }
                }
            } else {
                return Core::object_get_nested($keys_attribute_list[$nr], $object, $key_collect, $is_debug);
            }
        }
        return null;
    }

    /**
     * @throws ObjectException
     * @note
     * when patching an array, the whole array needs to be present,
     * put works as expected but patch cannot merge arrays, tried it, too complicated,
     * patch may work but cannot reset the array so also no put, so that's why the whole array gets rewritten.
     */
    public static function object_merge(): mixed
    {
        $objects = func_get_args();
        $main = array_shift($objects);
        if (empty($main) && !is_array($main)) {
            $main = (object) [];
        }
        foreach ($objects as $nr => $object) {
            if (is_array($object)) {
                foreach ($object as $key => $value) {
                    if (is_object($main)) {
                        throw new ObjectException(Core::EXCEPTION_MERGE_ARRAY_OBJECT);
                    }
                    if (!isset($main[$key])) {
                        $main[$key] = $value;
                    } else {
                        if (is_array($value) && is_array($main[$key])) {
                            $main[$key] = Core::object_merge($main[$key], $value);
                        } else {
                            $main[$key] = $value;
                        }
                    }
                }
            } elseif (is_object($object)) {
                foreach ($object as $key => $value) {
                    if ((!isset($main->{$key}))) {
                        $main->{$key} = $value;
                    } else {
                        if (is_object($value) && is_object($main->{$key})) {
                            try {
                                $main->{$key} = Core::object_merge(clone $main->{$key}, clone $value);
                            }
                            catch(Error | Exception $exception){
                                try {
                                    $main->{$key} = Core::object_merge(clone $main->{$key}, $value);
                                }
                                catch(Error | Exception $exception) {
                                    try {
                                        $main->{$key} = Core::object_merge($main->{$key}, clone $value);
                                    }
                                    catch (Error | Exception $exception) {
                                        try {
                                            $main->{$key} = Core::object_merge($main->{$key}, $value);
                                        }
                                        catch (Error | Exception $exception) {
                                            $main->{$key} = $exception;
                                        }
                                    }
                                }
                            }
                        } else {
                            $main->{$key} = $value;
                        }
                    }
                }
            }
        }
        return $main;
    }

    /**
     * @throws Exception
     */
    public static function object_set(mixed $attributeList=[], mixed $value=null, array|object|null $object=null, string $return='child'): mixed
    {
//        Core::interactive(); //maybe dangerous in template generation, it flushes directly and doesn't return parse.
        if(empty($object)){
            return null;
        }
        if(is_string($return) && $return !== 'child'){
            if($return === 'root'){
                $return = $object;
            } else {
                $return = Core::object_get($return, $object);
            }
        }
        if(is_scalar($attributeList)){
            $attributeList = Core::explode_multi(Core::ATTRIBUTE_EXPLODE, (string) $attributeList);
        }
        if(is_array($attributeList)){
            $attributeList = Core::object_horizontal($attributeList);
        }
        if(!empty($attributeList)){
            foreach($attributeList as $key => $attribute){
                if(is_object($object)){
                    if(
                        isset($object->{$key}) &&
                        is_object($object->{$key})
                    ){
                        if(
                            empty($attribute) &&
                            is_object($value)
                        ){
                            foreach($value as $value_key => $value_value){
                                /*
                                if(isset($object->$key->$value_key)){
                                    // unset($object->$key->$value_key);   //so sort will happen, @bug request will take forever and apache2 crashes needs reboot apache2
                                }
                                */
                                $object->{$key}->{$value_key} = $value_value;
                            }
                            return $object->{$key};
                        }
                        return Core::object_set($attribute, $value, $object->{$key}, $return);
                    }
                    elseif(is_object($attribute)){
                        if(
                            property_exists($object, $key) &&
                            is_array($object->{$key})
                        ){
                            foreach($attribute as $index => $unused){
                                $object->{$key}[$index] = $value;
                            }
                            return $object->{$key};
                        } else {
                            $object->{$key} = (object) [];
                        }
                        return Core::object_set($attribute, $value, $object->{$key}, $return);
                    }
                    else {
                        $object->{$key} = $value;
                    }
                }
                elseif(is_array($object)){
                    if(
                        array_key_exists($key, $object) &&
                        is_object($object[$key])
                    ){
                        if(empty($attribute) && is_object($value)){
                            foreach($value as $value_key => $value_value){
                                /*
                                if(isset($object->$key->$value_key)){
                                    // unset($object->$key->$value_key);   //so sort will happen, @bug request will take forever and apache2 crashes needs reboot apache2
                                }
                                */
                                $object[$key]->{$value_key} = $value_value;
                            }
                            return $object[$key];
                        }
                        return Core::object_set($attribute, $value, $object[$key], $return);
                    }
                    if(
                        array_key_exists($key, $object) &&
                        is_object($object[$key])
                    ){
                        if(empty($attribute) && is_object($value)){
                            foreach($value as $value_key => $value_value){
                                /*
                                if(isset($object->$key->$value_key)){
                                    // unset($object->$key->$value_key);   //so sort will happen, @bug request will take forever and apache2 crashes needs reboot apache2
                                }
                                */
                                $object[$key]->{$value_key} = $value_value;
                            }
                            return $object[$key];
                        }
                        return Core::object_set($attribute, $value, $object[$key], $return);
                    }
                    elseif(is_object($attribute)){
                        if(
                            array_key_exists($key, $object) &&
                            is_array($object[$key])
                        ){
                            foreach($attribute as $index => $unused){
                                $object[$key][$index] = $value;
                            }
                            return $object[$key];
                        } else {
                            $object[$key] = (object) [];
                        }
                        return Core::object_set($attribute, $value, $object[$key], $return);
                    }
                    elseif(is_object($object)) {
                        $object->{$key} = $value;
                    }
                    elseif(is_array($object)){
                        $object[$key] = $value;
                    }
                } else {
                    throw new Exception('Object::set only accepts objects and arrays.');
                }
            }
        }
        if($return == 'child'){
            return $value;
        }
        return $return;
    }

    public static function object_is_empty(array|object|null $object = null): bool
    {
        if (!is_object($object)) {
            return true;
        }
        $is_empty = true;
        foreach ($object as $value) {
            $is_empty = false;
            break;
        }
        return $is_empty;
    }

    public static function is_cli(): bool
    {
        if(defined('IS_CLI')){
            return true;
        }
        $domain = null;
        if (isset($_SERVER['HTTP_HOST'])) {
            $domain = $_SERVER['HTTP_HOST'];
        } elseif (isset($_SERVER['SERVER_NAME'])) {
            $domain = $_SERVER['SERVER_NAME'];
        }
        if ($domain === null) {
            if (!defined('IS_CLI')) {
                define('IS_CLI', true);
                return true;
            }
        }
        return false;
    }

    public static function object_horizontal(array|object $verticalArray = [], mixed $value = null, string $return = 'object'): bool | array | object
    {
        if (empty($verticalArray)) {
            return false;
        }
        $object = (object) [];
        if (is_object($verticalArray)) {
            $attributeList = get_object_vars($verticalArray);
            $list = array_keys($attributeList);
            $last = array_pop($list);
            if ($value === null) {
                $value = $verticalArray->$last;
            }
            $verticalArray = $list;
        } else {
            $last = array_pop($verticalArray);
        }
        if ($last === null || $last === '') {
            return false;
        }
        foreach ($verticalArray as $attribute) {
            if (empty($attribute) && $attribute !== '0') {
                continue;
            }
            if (!isset($deep)) {
                $object->{$attribute} = (object) [];
                $deep = $object->{$attribute};
            } else {
                $deep->{$attribute} = (object) [];
                $deep = $deep->{$attribute};
            }
        }
        if(strlen(trim($last)) === 0){
            $last = '\\' . $last;
        }
        if (!isset($deep)) {
            $object->$last = $value;
        } else {
            $deep->$last = $value;
        }
        if ($return == 'array') {
            $json = json_encode($object);
            return json_decode($json, true);
        } else {
            return $object;
        }
    }

    /**
     * @throws FileWriteException
     * @throws BadFormatException
     * @throws EnvironmentIsBrokenException
     */
    public static function key(string $url): Key
    {
        if (File::exist($url)) {
            $string = File::read($url);
            $key = Key::loadFromAsciiSafeString($string);
        } else {
            $key = Key::createNewRandomKey();
            $string = $key->saveToAsciiSafeString();
            $dir = Dir::name($url);
            Dir::create($dir, Dir::CHMOD);
            File::write($url, $string);
            if (posix_geteuid() === 0) {
                File::chown($dir, File::USER_WWW, File::USER_WWW, true);
            }
        }
        return $key;
    }

    public static function uuid(): string
    {
        $data = openssl_random_pseudo_bytes(16);
        assert(strlen($data) == 16);

        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function is_uuid(mixed $string=''): bool
    {
        //format: %s%s-%s-%s-%s-%s%s%s
        if(!is_string($string)){
            return false;
        }
        $explode = explode('-', $string);
        if(strlen($string) !== 36){
            return false;
        }
        if(count($explode) !== 5){
            return false;
        }
        if(strlen($explode[0]) !== 8){
            return false;
        }
        if(strlen($explode[1]) !== 4){
            return false;
        }
        if(strlen($explode[2]) !== 4){
            return false;
        }
        if(strlen($explode[3]) !== 4){
            return false;
        }
        if(strlen($explode[4]) !== 12){
            return false;
        }
        return true;
    }

    public static function uuid_variable(): string
    {
        $uuid = Core::uuid();
        $search = [];
        $search[] = 0;
        $search[] = 1;
        $search[] = 2;
        $search[] = 3;
        $search[] = 4;
        $search[] = 5;
        $search[] = 6;
        $search[] = 7;
        $search[] = 8;
        $search[] = 9;
        $search[] = '-';
        $replace = [];
        $replace[] = 'g';
        $replace[] = 'h';
        $replace[] = 'i';
        $replace[] = 'j';
        $replace[] = 'k';
        $replace[] = 'l';
        $replace[] = 'm';
        $replace[] = 'n';
        $replace[] = 'o';
        $replace[] = 'p';
        $replace[] = '_';
        $variable = '$' . str_replace($search, $replace, $uuid);
        return $variable;
    }

    public static function is_hex(mixed $string=''): bool
    {
        if(strtoupper(substr($string, 0, 2)) === '0X'){
            return ctype_xdigit(substr($string, 2));
        }
        return false;
    }

    public static function ucfirst_sentence(string $string='', string $delimiter='.'): string
    {
        $explode = explode($delimiter, $string);
        foreach ($explode as $nr => $part) {
            $explode[$nr] = ucfirst(trim($part));
        }
        return implode($delimiter, $explode);
    }

    public static function deep_clone(array|object $object): array | object
    {
        if (is_array($object)) {
            foreach ($object as $key => $value) {
                if (is_object($value)) {
                    $object[$key] = Core::deep_clone($value);
                }
            }
            return $object;
        }
        $clone = clone $object;
        foreach ($object as $key => $value) {
            if (is_object($value)) {
                $clone->$key = Core::deep_clone($value);
            }
        }
        return $clone;
    }

    /**
     * @throws ObjectException
     * @throws FileWriteException
     * @throws Exception
     */
    public static function object_select(Parse $parse, Data $data, string $url='', ?string $select=null, bool $compile=false, string $scope='scope:object'): mixed
    {
        $object = $parse->object();
        $logger_error = $object->config('project.log.error');
        if(
            $compile === true &&
            in_array(
                $scope,
                [
                    'object',
                    'scope:object'
                ],
                true
            )
        ){
            $read = Core::object_select(
                $parse,
                $data,
                $url,
                $select,
                false
            );
            if(empty($read)){
                if($logger_error){
                    $object->logger($logger_error)->error('Could not compile item: ' . $select, [$url, $compile, $scope]);
                }
                throw new ObjectException('Could not compile item: ' . $select . ', url: ' . $url  . ', scope: ' . $scope . PHP_EOL);
            }
            if(is_array($read)){
                $explode = explode('.', $select, 2);
                $key = array_pop($explode);
                foreach($read as $nr => $record){
                    if(is_object($record)){
                        $record->{$parse->object()->config('package.raxon/parse.object.this.property')} = $key;
                        $record->{$parse->object()->config('package.raxon/parse.object.this.attribute')} = $key;
                    }
                }
                $parse_options = $parse->options();
                $options = (object) [
                    'source' => hash('sha256', Core::object($read, Core::JSON_LINE)),
                ];
                $parse->options($options);
                $result = $parse->compile($read, $data->data());
                $parse->options($parse_options);
                return $result;
            } else {
                $explode = explode('.', $select, 2);
                $key = array_pop($explode);
                $read->{$parse->object()->config('package.raxon/parse.object.this.property')} = $key;
                $read->{$parse->object()->config('package.raxon/parse.object.this.attribute')} = $key;
                $parse_options = $parse->options();
                $options = (object) [
                    'source' => hash('sha256', Core::object($read, Core::JSON_LINE)),
                ];
                $parse->options($options);
                $result = $parse->compile($read, $data->data());
                $parse->options($parse_options);
                return $result;
            }
        } else {
            //document
            //scope:document
            if (File::exist($url)) {
                $read = File::read($url);
                $read = Core::object($read);
                if(empty($read)){
                    if($logger_error){
                        $object->logger($logger_error)->error('Could not compile item: ' . $select, [$url, $compile, $scope]);
                    }
                    throw new ObjectException('Could not read item: ' . $select . PHP_EOL);
                }
                if ($compile) {
                    $parse_options = $parse->options();
                    $options = (object) [
                        'source' => hash('sha256', Core::object($read, Core::JSON_LINE)),
                        'depth' => 0
                    ];
                    $parse->options($options);
                    $read = $parse->compile($read, $data->data());
                    $parse->options($parse_options);
                }
                $json = new Data();
                $json->data($read);
                return $json->get($select);
            }
            return null;
        }
    }
}