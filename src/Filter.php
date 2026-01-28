<?php
/**
 * @author          Remco van der Velde
 * @since           2026-01-28
 * @copyright       (c) Remco van der Velde
 * @license         MIT
 * @version         1.0
 */
namespace Module;

use Exception\LocateException;
use Exception\ObjectException;
use Exception;

class Filter extends Data {
    const NAME = 'Filter';
    const INPUT = 'input';
    const OUTPUT = 'output';
    const OPERATOR_STRICTLY_EXACT = 'strictly-exact';
    const OPERATOR_STRICTLY_EQUAL = 'strictly-equal';
    const OPERATOR_NOT_STRICTLY_EXACT = 'not-strictly-exact';
    const OPERATOR_NOT_STRICTLY_EQUAL = 'not-strictly-equal';
    const OPERATOR_EXACT = 'exact';
    const OPERATOR_EQUAL = 'equal';
    const OPERATOR_NOT_EXACT = 'not-exact';
    const OPERATOR_NOT_EQUAL = 'not-equal';
    const OPERATOR_IN = 'in';
    const OPERATOR_NOT_IN = 'not-in';
    const OPERATOR_GT = 'gt';
    const OPERATOR_GREATER_THAN = 'greater-than';
    const OPERATOR_GTE = 'gte';
    const OPERATOR_GREATER_THAN_EQUAL = 'greater-than-equal';
    const OPERATOR_LT = 'lt';
    const OPERATOR_LOWER_THAN = 'lower-than';
    const OPERATOR_LTE = 'lte';
    const OPERATOR_LOWER_THAN_EQUAL = 'lower-than-equal';
    const OPERATOR_BETWEEN = 'between';
    const OPERATOR_BETWEEN_EQUALS = 'between-equals';
    const OPERATOR_BEFORE = 'before';
    const OPERATOR_AFTER = 'after';
    const OPERATOR_STRICTLY_BEFORE = 'strictly-before';
    const OPERATOR_STRICTLY_AFTER = 'strictly-after';
    const OPERATOR_PARTIAL = 'partial';
    const OPERATOR_NOT_PARTIAL = 'not-partial';
    const OPERATOR_START = 'start';
    const OPERATOR_NOT_START = 'not-start';
    const OPERATOR_END = 'end';
    const OPERATOR_NOT_END = 'not-end';

    const OPERATOR_LIST_NAME = [
        Filter::OPERATOR_STRICTLY_EXACT,
        Filter::OPERATOR_NOT_STRICTLY_EXACT,
        Filter::OPERATOR_EXACT,
        Filter::OPERATOR_NOT_EXACT,
        Filter::OPERATOR_IN,
        Filter::OPERATOR_NOT_IN,
        Filter::OPERATOR_GT,
        Filter::OPERATOR_GTE,
        Filter::OPERATOR_LT,
        Filter::OPERATOR_LTE,
        Filter::OPERATOR_BETWEEN,
        Filter::OPERATOR_BETWEEN_EQUALS,
        Filter::OPERATOR_BEFORE,
        Filter::OPERATOR_AFTER,
        Filter::OPERATOR_STRICTLY_BEFORE,
        Filter::OPERATOR_STRICTLY_AFTER,
        Filter::OPERATOR_PARTIAL,
        Filter::OPERATOR_NOT_PARTIAL,
        Filter::OPERATOR_START,
        Filter::OPERATOR_NOT_START,
        Filter::OPERATOR_END,
        Filter::OPERATOR_NOT_END,
        Filter::OPERATOR_GREATER_THAN,
        Filter::OPERATOR_GREATER_THAN_EQUAL,
        Filter::OPERATOR_LOWER_THAN,
        Filter::OPERATOR_LOWER_THAN_EQUAL,
        Filter::OPERATOR_EQUAL,
        Filter::OPERATOR_NOT_EQUAL,
        Filter::OPERATOR_STRICTLY_EQUAL,
        Filter::OPERATOR_NOT_STRICTLY_EQUAL
    ];

    private $type;

    const TYPE_RECORD = 'record';
    const TYPE_LIST = 'list';
    const TYPE_AUTO = 'auto';

    /**
     * @throws Exception
     */
    public static function list($list): Filter
    {
        $filter = new Filter($list);
        $filter->type(__FUNCTION__);
        return $filter;
    }

    /**
     * @throws Exception
     */
    public static function record($record): Filter
    {
        $filter = new Filter($record);
        $filter->type(__FUNCTION__);
        return $filter;
    }

    public function type($type=null): ?string
    {
        if($type !== null){
            $this->setType($type);
        }
        return $this->getType();
    }

    private function setType($type): void
    {
        $this->type = $type;
    }

    private function getType() : ?string
    {
        return $this->type;
    }

    public static function is_type($data=null): string
    {
        if(is_array($data)){
            return Filter::TYPE_LIST;
        }
        $is_iterable = false;
        if(
            is_object($data)
        ){
            $is_iterable = true;
            foreach($data as $record){
                if($record === null) {
                    $is_iterable = false;
                    break;
                }
                elseif(is_scalar($record)){
                    $is_iterable = false;
                    break;
                }
            }
        }
        switch($is_iterable){
            case true :
                return Filter::TYPE_LIST;
            default:
                return Filter::TYPE_RECORD;
        }
    }

    /**
     * @throws Exception
     */
    private static function date($record=[]): bool | int
    {
        if(array_key_exists('value', $record)){
            if(is_string($record['value'])){
                $record_date = strtotime($record['value']);
            }
            elseif(is_int($record['value'])){
                $record_date = $record['value'];
            } else {
                throw new Exception('Not a date.');
            }
            return $record_date;
        }
        throw new Exception('Date: no value.');
    }

    private static function object_clean($where){
        if(!is_object($where)){
            return $where;
        }
        foreach($where as $property => $value){
            if(is_object($value)){
                $where->{$property} = Filter::object_clean($value);
            }
            if(
                substr($property, 0, 1) === '#' &&
                is_scalar($value)
            ){
                unset($where->$property);
            }
        }
        return $where;
    }

    /**
     * @throws ObjectException
     * @throws Exception
     */
    private function where_list($where=[]){
        $list = $this->data();
        if(is_object($where)){
            $where = Filter::object_clean($where);
            $where = Core::object($where, Core::OBJECT_ARRAY);
        }
        if(
            is_array($list) ||
            is_object($list)
        ){
            if(
                is_object($list) &&
                Core::object_is_empty($list)){
                return [];
            }
            foreach($list as $nr => $node) {
                if (is_object($node)) {
                    $data = new Data($node);
                    foreach ($where as $attribute => $record) {
                        if (
                            is_array($record) &&
                            array_key_exists('exist', $record)
                        ) {
                            if (!empty($record['exist'])) {
                                if (
                                    is_object($node) &&
                                    !property_exists($node, $attribute)
                                ) {
                                    if($this->type() === 'list'){
                                        $this->data('delete', $nr);
                                        unset($list->$nr);
                                    }
                                }
                            } else {
                                if (
                                    is_object($node) &&
                                    property_exists($node, $attribute)
                                ) {
                                    if($this->type() === 'list'){
                                        $this->data('delete', $nr);
                                        unset($list->$nr);
                                    }

                                }
                            }
                        }
                        if (
                            is_array($record) &&
                            array_key_exists('exists', $record)
                        ) {
                            if (!empty($record['exists'])) {
                                if (
                                    is_object($node) &&
                                    !property_exists($node, $attribute)
                                ) {
                                    if($this->type() === 'list'){
                                        $this->data('delete', $nr);
                                        unset($list->$nr);
                                    }

                                }
                            } else {
                                if (
                                    is_object($node) &&
                                    property_exists($node, $attribute)
                                ) {
                                    if($this->type() === 'list'){
                                        $this->data('delete', $nr);
                                        unset($list->$nr);
                                    }
                                }
                            }
                        }
                        if (
                            is_array($record) &&
                            array_key_exists('operator', $record) &&
                            array_key_exists('value', $record)
                        ) {
                            $skip = false;
                            $strict = $record['strict'] ?? true;
                            switch ($record['operator']) {
                                case '===' :
                                case Filter::OPERATOR_STRICTLY_EXACT :
                                case Filter::OPERATOR_STRICTLY_EQUAL :
                                    $value = $data->get($attribute);
                                    if (
                                        $value === null ||
                                        is_scalar($value)
                                    ) {
                                        if ($value === $record['value']) {
                                            $skip = true;
                                        }
                                    } elseif (is_array($value)) {
                                        if (in_array($record['value'], $value, true)) {
                                            $skip = true;
                                        }
                                    }
                                    break;
                                case '!==' :
                                case Filter::OPERATOR_NOT_STRICTLY_EXACT :
                                case Filter::OPERATOR_NOT_STRICTLY_EQUAL :
                                    $value = $data->get($attribute);
                                    if (
                                        $value === null ||
                                        is_scalar($value)
                                    ) {
                                        if ($value !== $record['value']) {
                                            $skip = true;
                                        }
                                    } elseif (is_array($value)) {
                                        if (!in_array($record['value'], $value, true)) {
                                            $skip = true;
                                        }
                                    }
                                    break;
                                case '==' :
                                case Filter::OPERATOR_EXACT :
                                case Filter::OPERATOR_EQUAL :
                                    $value = $data->get($attribute);
                                    if (
                                        $value === null ||
                                        is_scalar($value)
                                    ) {
                                        if ($value == $record['value']) {
                                            $skip = true;
                                        }
                                    } elseif (is_array($value)) {
                                        if (in_array($record['value'], $value, true)) {
                                            $skip = true;
                                        }
                                    }
                                    break;
                                case '!=' :
                                case Filter::OPERATOR_NOT_EXACT :
                                case Filter::OPERATOR_NOT_EQUAL :
                                    $value = $data->get($attribute);
                                    if (
                                        $value === null ||
                                        is_scalar($value)
                                    ) {
                                        if ($value != $record['value']) {
                                            $skip = true;
                                        }
                                    } elseif (is_array($value)) {
                                        if (!in_array($record['value'], $value, true)) {
                                            $skip = true;
                                        }
                                    }
                                    break;
                                case Filter::OPERATOR_IN :
                                    $value = $data->get($attribute);
                                    if (is_array($record['value'])) {
                                        if (
                                            $value === null ||
                                            is_scalar($value)
                                        ) {
                                            if (
                                                $strict === true &&
                                                in_array(
                                                    $value,
                                                    $record['value'],
                                                    true)
                                            ) {
                                                $skip = true;
                                            }
                                            elseif ($strict === false) {
                                                if ($value === 'true') {
                                                    $value = true;
                                                }
                                                elseif ($value === 'false') {
                                                    $value = false;
                                                }
                                                elseif ($value === 'null') {
                                                    $value = null;
                                                }
                                                elseif (is_numeric($value)) {
                                                    $value += 0;
                                                }
                                                foreach ($record['value'] as $record_value_key => $record_value_value) {
                                                    if ($record_value_value === 'true') {
                                                        $record_value_value = true;
                                                    }
                                                    elseif ($record_value_value === 'false') {
                                                        $record_value_value = false;
                                                    }
                                                    elseif ($record_value_value === 'null') {
                                                        $record_value_value = null;
                                                    }
                                                    elseif (is_numeric($record_value_value)) {
                                                        $record_value_value += 0;
                                                    }
                                                    if ($record_value_value == $value) {
                                                        $skip = true;
                                                        break;
                                                    }
                                                }
                                            }
                                        } elseif (is_array($value)) {
                                            foreach ($value as $value_key => $value_value) {
                                                if (
                                                    $strict === true &&
                                                    in_array(
                                                        $value_value,
                                                        $record['value'],
                                                        true)
                                                ) {
                                                    $skip = true;
                                                    break;
                                                }
                                                elseif ($strict === false) {
                                                    if ($value_value === 'true') {
                                                        $value_value = true;
                                                    }
                                                    elseif ($value_value === 'false') {
                                                        $value_value = false;
                                                    }
                                                    elseif ($value_value === 'null') {
                                                        $value_value = null;
                                                    } elseif (is_numeric($value_value)) {
                                                        $value_value += 0;
                                                    }
                                                    foreach ($record['value'] as $record_value_key => $record_value_value) {
                                                        if ($record_value_value === 'true') {
                                                            $record_value_value = true;
                                                        }
                                                        elseif ($record_value_value === 'false') {
                                                            $record_value_value = false;
                                                        }
                                                        elseif ($record_value_value === 'null') {
                                                            $record_value_value = null;
                                                        }
                                                        elseif (is_numeric($record_value_value)) {
                                                            $record_value_value += 0;
                                                        }
                                                        if ($record_value_value == $value_value) {
                                                            $skip = true;
                                                            break;
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    } elseif (
                                        $record['value'] === null ||
                                        is_scalar($record['value'])
                                    ) {
                                        if (
                                            $value === null ||
                                            is_scalar($value)
                                        ) {
                                            if (
                                                $strict === true &&
                                                $value === $record['value']
                                            ) {
                                                $skip = true;
                                            }
                                        }
                                        elseif ($strict === false) {
                                            if ($value === 'true') {
                                                $value = true;
                                            }
                                            elseif ($value === 'false') {
                                                $value = false;
                                            }
                                            elseif ($value === 'null') {
                                                $value = null;
                                            }
                                            elseif (is_numeric($value)) {
                                                $value += 0;
                                            }
                                            $record_value = $record['value'];
                                            if ($record_value === 'true') {
                                                $record_value = true;
                                            }
                                            elseif ($record_value === 'false') {
                                                $record_value = false;
                                            }
                                            elseif ($record_value === 'null') {
                                                $record_value = null;
                                            }
                                            elseif (is_numeric($record_value)) {
                                                $record_value += 0;
                                            }
                                            if ($value == $record_value) {
                                                $skip = true;
                                            }
                                        }
                                    }
                                    elseif (is_array($value)) {
                                        if (
                                            $strict === true &&
                                            in_array($record['value'], $value, true)
                                        ) {
                                            $skip = true;
                                        }
                                        elseif ($strict === false) {
                                            $record_value = $record['value'];
                                            if ($record_value === 'true') {
                                                $record_value = true;
                                            }
                                            elseif ($record_value === 'false') {
                                                $record_value = false;
                                            }
                                            elseif ($record_value === 'null') {
                                                $record_value = null;
                                            }
                                            elseif (is_numeric($record_value)) {
                                                $record_value += 0;
                                            }
                                            if ($value == $record_value) {
                                                $skip = true;
                                            }
                                            foreach ($value as $value_key => $value_value) {
                                                if ($value_value === 'true') {
                                                    $value_value = true;
                                                }
                                                elseif ($value_value === 'false') {
                                                    $value_value = false;
                                                }
                                                elseif ($value_value === 'null') {
                                                    $value_value = null;
                                                }
                                                elseif (is_numeric($value_value)) {
                                                    $value_value += 0;
                                                }
                                                if ($value_value == $record['value']) {
                                                    $skip = true;
                                                    break;
                                                }
                                            }
                                        }
                                    }
                                    break;
                                case Filter::OPERATOR_NOT_IN :
                                    $value = $data->get($attribute);
                                    if (is_array($record['value'])) {
                                        if (
                                            $value === null ||
                                            is_scalar($value)
                                        ) {
                                            if(
                                                $strict === true &&
                                                !in_array(
                                                    $value,
                                                    $record['value'],
                                                    true
                                                )
                                            ) {
                                                    $skip = true;
                                            }
                                            elseif($strict === false) {
                                                if($value === 'true'){
                                                    $value = true;
                                                }
                                                elseif($value === 'false'){
                                                    $value = false;
                                                }
                                                elseif($value === 'null'){
                                                    $value = null;
                                                }
                                                elseif(is_numeric($value)){
                                                    $value += 0;
                                                }
                                                $skip = true;
                                                foreach($record['value'] as $record_value_key => $record_value_value){
                                                    if($record_value_value === 'true'){
                                                        $record_value_value = true;
                                                    }
                                                    elseif($record_value_value === 'false'){
                                                        $record_value_value = false;
                                                    }
                                                    elseif($record_value_value === 'null'){
                                                        $record_value_value = null;
                                                    }
                                                    elseif(is_numeric($record_value_value)){
                                                        $record_value_value += 0;
                                                    }
                                                    if($record_value_value == $value){
                                                        $skip = false;
                                                        break;
                                                    }
                                                }
                                            }
                                        }
                                        elseif (is_array($value)) {
                                            $skip = true;
                                            foreach ($value as $value_key => $value_value) {
                                                if(
                                                    $strict === true &&
                                                    in_array(
                                                        $value_value,
                                                        $record['value'],
                                                        true
                                                    )
                                                ) {
                                                    $skip = false;
                                                    break;
                                                }
                                                elseif($strict === false) {
                                                    if($value_value === 'true'){
                                                        $value_value = true;
                                                    }
                                                    elseif($value_value === 'false'){
                                                        $value_value = false;
                                                    }
                                                    elseif($value_value === 'null'){
                                                        $value_value = null;
                                                    }
                                                    elseif(is_numeric($value_value)){
                                                        $value_value += 0;
                                                    }
                                                    foreach($record['value'] as $record_value_key => $record_value_value){
                                                        if($record_value_value === 'true'){
                                                            $record_value_value = true;
                                                        }
                                                        elseif($record_value_value === 'false'){
                                                            $record_value_value = false;
                                                        }
                                                        elseif($record_value_value === 'null'){
                                                            $record_value_value = null;
                                                        }
                                                        elseif(is_numeric($record_value_value)){
                                                            $record_value_value += 0;
                                                        }
                                                        if($record_value_value == $value_value){
                                                            $skip = false;
                                                            break;
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                    elseif(
                                        $record['value'] === null ||
                                        is_scalar($record['value'])
                                    ) {
                                        if(
                                            $value === null ||
                                            is_scalar($value)
                                        ){
                                            if(
                                                $strict === true &&
                                                $value !== $record['value']
                                            ){
                                                $skip = true;
                                            }
                                            elseif($strict === false) {
                                                if($value === 'true'){
                                                    $value = true;
                                                }
                                                elseif($value === 'false'){
                                                    $value = false;
                                                }
                                                elseif($value === 'null'){
                                                    $value = null;
                                                }
                                                elseif(is_numeric($value)){
                                                    $value += 0;
                                                }
                                                $record_value = $record['value'];
                                                if($record_value === 'true'){
                                                    $record_value = true;
                                                }
                                                elseif($record_value === 'false'){
                                                    $record_value = false;
                                                }
                                                elseif($record_value === 'null'){
                                                    $record_value = null;
                                                }
                                                elseif(is_numeric($record_value)){
                                                    $record_value += 0;
                                                }
                                                if($value != $record_value){
                                                    $skip = true;
                                                }
                                            }
                                        }
                                        elseif(is_array($value)){
                                            if(
                                                $strict === true &&
                                                !in_array($record['value'], $value, true)
                                            ){
                                                $skip = true;
                                            }
                                            elseif($strict === false) {
                                                $skip = true;
                                                $record_value = $record['value'];
                                                if($record_value === 'true'){
                                                    $record_value = true;
                                                }
                                                elseif($record_value === 'false'){
                                                    $record_value = false;
                                                }
                                                elseif($record_value === 'null'){
                                                    $record_value = null;
                                                }
                                                elseif(is_numeric($record_value)){
                                                    $record_value += 0;
                                                }
                                                foreach($value as $value_value){
                                                    if($value_value === 'true'){
                                                        $value_value = true;
                                                    }
                                                    elseif($value_value === 'false'){
                                                        $value_value = false;
                                                    }
                                                    elseif($value_value === 'null'){
                                                        $value_value = null;
                                                    }
                                                    elseif(is_numeric($value_value)){
                                                        $value_value += 0;
                                                    }
                                                    if($record_value == $value_value){
                                                        $skip = false;
                                                        break;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                    break;
                                case '>' :
                                case Filter::OPERATOR_GT :
                                case FILTER::OPERATOR_GREATER_THAN :
                                    $value = $data->get($attribute);
                                    if (is_scalar($value)) {
                                        if ($value > $record['value']) {
                                            $skip = true;
                                        }
                                    }
                                    elseif (is_array($value)) {
                                        $found = false;
                                        foreach ($value as $value_key => $value_value) {
                                            if ($value_value > $record['value']) {
                                            } else {
                                                $found = true;
                                                break;
                                            }
                                        }
                                        if (!$found) {
                                            $skip = true;
                                        }
                                    }
                                    break;
                                case '>=' :
                                case Filter::OPERATOR_GTE :
                                case FILTER::OPERATOR_GREATER_THAN_EQUAL :
                                    $value = $data->get($attribute);
                                    if (is_scalar($value)) {
                                        if ($value >= $record['value']) {
                                            $skip = true;
                                        }
                                    }
                                    elseif (is_array($value)) {
                                        $found = false;
                                        foreach ($value as $value_key => $value_value) {
                                            if ($value_value >= $record['value']) {
                                            } else {
                                                $found = true;
                                                break;
                                            }
                                        }
                                        if (!$found) {
                                            $skip = true;
                                        }
                                    }
                                    break;
                                case '<' :
                                case Filter::OPERATOR_LT :
                                case FILTER::OPERATOR_LOWER_THAN :
                                    $value = $data->get($attribute);
                                    if (is_scalar($value)) {
                                        if ($value < $record['value']) {
                                            $skip = true;
                                        }
                                    }
                                    elseif (is_array($value)) {
                                        $found = false;
                                        foreach ($value as $value_key => $value_value) {
                                            if ($value_value < $record['value']) {
                                            } else {
                                                $found = true;
                                                break;
                                            }
                                        }
                                        if (!$found) {
                                            $skip = true;
                                        }
                                    }
                                    break;
                                case '<=' :
                                case Filter::OPERATOR_LTE :
                                case FILTER::OPERATOR_LOWER_THAN_EQUAL :
                                    $value = $data->get($attribute);
                                    if (is_scalar($value)) {
                                        if ($value <= $record['value']) {
                                            $skip = true;
                                        }
                                    }
                                    elseif (is_array($value)) {
                                        $found = false;
                                        foreach ($value as $value_key => $value_value) {
                                            if ($value_value <= $record['value']) {
                                            } else {
                                                $found = true;
                                                break;
                                            }
                                        }
                                        if (!$found) {
                                            $skip = true;
                                        }
                                    }
                                    break;
                                case '> <' :
                                case Filter::OPERATOR_BETWEEN :
                                    $value = $data->get($attribute);
                                    $explode = explode('..', $record['value'], 2);
                                    if (array_key_exists(1, $explode)) {
                                        if (is_numeric($explode[0])) {
                                            $explode[0] += 0;
                                        }
                                        if (is_numeric($explode[1])) {
                                            $explode[1] += 0;
                                        }
                                        if (is_array($value)) {
                                            foreach ($value as $value_key => $value_value) {
                                                if (
                                                    $value_value > $explode[0] &&
                                                    $value_value < $explode[1]
                                                ) {
                                                    $skip = true;
                                                    break;
                                                }
                                            }
                                        }
                                        elseif (
                                            $value > $explode[0] &&
                                            $value < $explode[1]
                                        ) {
                                            $skip = true;
                                        }
                                    } else {
                                        throw new Exception('Value is range: ?..?');
                                    }
                                    break;
                                case '>=<' :
                                case Filter::OPERATOR_BETWEEN_EQUALS :
                                    $value = $data->get($attribute);
                                    $explode = explode('..', $record['value'], 2);
                                    if (array_key_exists(1, $explode)) {
                                        if (is_numeric($explode[0])) {
                                            $explode[0] += 0;
                                        }
                                        if (is_numeric($explode[1])) {
                                            $explode[1] += 0;
                                        }
                                        if (is_array($value)) {
                                            foreach ($value as $value_key => $value_value) {
                                                if (
                                                    $value_value >= $explode[0] &&
                                                    $value_value <= $explode[1]
                                                ) {
                                                    $skip = true;
                                                    break;
                                                }
                                            }
                                        }
                                        elseif (
                                            $value >= $explode[0] &&
                                            $value <= $explode[1]
                                        ) {
                                            $skip = true;
                                        }

                                    } else {
                                        throw new Exception('Value is range: ?..?');
                                    }
                                    break;
                                case Filter::OPERATOR_BEFORE :
                                    $value = $data->get($attribute);
                                    if (is_string($value)) {
                                        $node_date = strtotime($value);
                                        $record_date = Filter::date($record);
                                    }
                                    elseif (is_int($value)) {
                                        $node_date = $value;
                                        $record_date = Filter::date($record);
                                    } else {
                                        throw new Exception('Cannot calculate: before');
                                    }
                                    if (
                                        $node_date <=
                                        $record_date
                                    ) {
                                        $skip = true;
                                    }
                                    break;
                                case Filter::OPERATOR_AFTER :
                                    $value = $data->get($attribute);
                                    if (is_string($value)) {
                                        $node_date = strtotime($value);
                                        $record_date = Filter::date($record);
                                    }
                                    elseif (is_int($value)) {
                                        $node_date = $value;
                                        $record_date = Filter::date($record);
                                    } else {
                                        throw new Exception('Cannot calculate: before');
                                    }
                                    if (
                                        $node_date >=
                                        $record_date
                                    ) {
                                        $skip = true;
                                    }
                                    break;
                                case Filter::OPERATOR_STRICTLY_BEFORE :
                                    $value = $data->get($attribute);
                                    if (is_string($value)) {
                                        $node_date = strtotime($value);
                                        $record_date = Filter::date($record);
                                    }
                                    elseif (is_int($value)) {
                                        $node_date = $value;
                                        $record_date = Filter::date($record);
                                    } else {
                                        throw new Exception('Cannot calculate: before');
                                    }
                                    if (
                                        $node_date <
                                        $record_date
                                    ) {
                                        $skip = true;
                                    }
                                    break;
                                case Filter::OPERATOR_STRICTLY_AFTER :
                                    $value = $data->get($attribute);
                                    if (is_string($value)) {
                                        $node_date = strtotime($value);
                                        $record_date = Filter::date($record);
                                    }
                                    elseif (is_int($value)) {
                                        $node_date = $value;
                                        $record_date = Filter::date($record);
                                    } else {
                                        throw new Exception('Cannot calculate: before');
                                    }
                                    if (
                                        $node_date >
                                        $record_date
                                    ) {
                                        $skip = true;
                                    }
                                    break;
                                case Filter::OPERATOR_PARTIAL :
                                    $value = $data->get($attribute);
                                    if (
                                        is_scalar($record['value'])
                                    ) {
                                        if ($record['value'] === '') {
                                            break;
                                        }
                                        elseif (is_array($value)) {
                                            foreach ($value as $value_key => $value_value) {
                                                if (
                                                    is_string($value_value) &&
                                                    is_string($record['value'])
                                                ) {
                                                    if (stristr($value_value, $record['value']) !== false) {
                                                        $skip = true;
                                                        break;
                                                    }
                                                }
                                                elseif (is_scalar($value_value)) {
                                                    if ($value == $record['value']) {
                                                        $skip = true;
                                                        break;
                                                    }
                                                }
                                            }
                                        }
                                        elseif (
                                            is_string($value) &&
                                            is_string($record['value'])
                                        ) {
                                            if (stristr($value, $record['value']) !== false) {
                                                $skip = true;
                                            }
                                        }
                                        elseif (is_scalar($value)) {
                                            if ($value == $record['value']) {
                                                $skip = true;
                                            }
                                        }
                                    }
                                    break;
                                case Filter::OPERATOR_NOT_PARTIAL :
                                    $value = $data->get($attribute);
                                    if (is_scalar($record['value'])) {
                                        if ($record['value'] === '') {
                                            break;
                                        }
                                        elseif (is_array($value)) {
                                            foreach ($value as $value_key => $value_value) {
                                                if (is_string($value_value) && is_string($record['value'])) {
                                                    if (stristr($value_value, $record['value']) === false) {
                                                        $skip = true;
                                                        break;
                                                    }
                                                }
                                                elseif (is_scalar($value_value)) {
                                                    if ($value != $record['value']) {
                                                        $skip = true;
                                                        break;
                                                    }
                                                }
                                            }
                                        }
                                        elseif (
                                            is_string($value) && is_string($record['value'])
                                        ) {
                                            if (stristr($value, $record['value']) === false) {
                                                $skip = true;
                                            }
                                        }
                                        elseif (is_scalar($value)) {
                                            if ($value != $record['value']) {
                                                $skip = true;
                                            }
                                        }
                                    }
                                    break;
                                case Filter::OPERATOR_START :
                                    $value = $data->get($attribute);
                                    if (
                                        is_string($record['value'])
                                    ) {
                                        if ($record['value'] === '') {
                                            break;
                                        }
                                        elseif (
                                            is_string($value) &&
                                            stristr(
                                                substr(
                                                    $value,
                                                    0,
                                                    strlen($record['value'])
                                                ),
                                                $record['value']
                                            ) !== false
                                        ) {
                                            $skip = true;
                                        }
                                    }
                                    elseif (is_array($value)) {
                                        foreach ($value as $value_key => $value_value) {
                                            $record_value = (string)$record['value'];
                                            if ($value_value === '') {
                                                continue;
                                            }
                                            elseif (
                                                is_string($value_value) &&
                                                stristr(
                                                    substr(
                                                        $value_value,
                                                        0,
                                                        strlen($record_value)
                                                    ),
                                                    $record_value
                                                ) !== false
                                            ) {
                                                $skip = true;
                                            }
                                        }
                                    }
                                    break;
                                case Filter::OPERATOR_NOT_START :
                                    $value = $data->get($attribute);
                                    if (
                                        is_string($record['value'])
                                    ) {
                                        if ($record['value'] === '') {
                                            break;
                                        }
                                        elseif (
                                            is_string($value) &&
                                            stristr(
                                                substr(
                                                    $value,
                                                    0,
                                                    strlen($record['value'])
                                                ),
                                                $record['value']
                                            ) === false
                                        ) {
                                            $skip = true;
                                        }
                                        elseif (is_array($value)) {
                                            foreach ($value as $value_key => $value_value) {
                                                if ($value_value === '') {
                                                    continue;
                                                }
                                                elseif (
                                                    is_string($value_value) &&
                                                    stristr(
                                                        substr(
                                                            $value_value,
                                                            0,
                                                            strlen($record['value'])
                                                        ),
                                                        $record['value']
                                                    ) === false
                                                ) {
                                                    $skip = true;
                                                }
                                            }
                                        }
                                    }
                                    break;
                                case Filter::OPERATOR_END :
                                    $value = $data->get($attribute);
                                    if (is_string($record['value'])) {
                                        if ($record['value'] === '') {
                                            break;
                                        }
                                        $length = strlen($record['value']);
                                        if (is_array($value)) {
                                            foreach ($value as $value_key => $value_value) {
                                                if ($value_value === '') {
                                                    continue;
                                                }
                                                $start = strlen($value_value) - $length;
                                                if (
                                                    stristr(
                                                        substr(
                                                            $value_value,
                                                            $start,
                                                            $length
                                                        ),
                                                        $record['value']
                                                    ) !== false
                                                ) {
                                                    $skip = true;
                                                    break;
                                                }
                                            }
                                        }
                                        elseif (is_string($value)) {
                                            $start = strlen($value) - $length;
                                            if (
                                                stristr(
                                                    substr(
                                                        $value,
                                                        $start,
                                                        $length
                                                    ),
                                                    $record['value']
                                                ) !== false
                                            ) {
                                                $skip = true;
                                            }
                                        }
                                    }
                                    break;
                                case Filter::OPERATOR_NOT_END :
                                    $value = $data->get($attribute);
                                    if (
                                        is_string($record['value'])
                                    ) {
                                        if ($record['value'] === '') {
                                            break;
                                        }
                                        $length = strlen($record['value']);
                                        if (is_array($value)) {
                                            foreach ($value as $value_key => $value_value) {
                                                if ($value_value === '') {
                                                    continue;
                                                }
                                                $start = strlen($value_value) - $length;
                                                if (
                                                    stristr(
                                                        substr(
                                                            $value_value,
                                                            $start,
                                                            $length
                                                        ),
                                                        $record['value']
                                                    ) === false
                                                ) {
                                                    $skip = true;
                                                    break;
                                                }
                                            }
                                        }
                                        elseif (is_string($value)) {
                                            $start = strlen($value) - $length;
                                            if (
                                                stristr(
                                                    substr(
                                                        $value,
                                                        $start,
                                                        $length
                                                    ),
                                                    $record['value']
                                                ) === false
                                            ) {
                                                $skip = true;
                                            }
                                        }
                                    }
                                    break;
                            }
                            if ($skip === false) {
                                switch($this->type()) {
                                    case 'list':
                                        $this->data('delete', $nr);
                                        if (is_array($list)) {
                                            unset($list[$nr]);
                                        } else {
                                            unset($list->$nr);
                                        }
                                        break;
                                    case 'record':
                                        return [];
                                }
                            }
                        }
                        elseif (is_array($record)) {
                            $where = [];
                            foreach ($record as $key => $value) {
                                $where[$attribute] = [
                                    'operator' => Filter::OPERATOR_STRICTLY_EXACT,
                                    'value' => $value
                                ];
                            }
                            $list = Filter::list($list)->where($where);
                        } else {
                            $where = [];
                            $where[$attribute] = [
                                'operator' => Filter::OPERATOR_STRICTLY_EXACT,
                                'value' => $record
                            ];
                            $list = Filter::list($list)->where($where);
                        }
                    }
                } else {
                    return false;
                }
            }
        }
        return $list;
    }

    /**
     * @throws ObjectException
     * @throws Exception
     */
    private function where_record($where=[]): mixed
    {
        $record = clone $this->data();
        $this->reset(true);
        $this->data([ $record ]);
        $list = $this->where_list($where);
        if($list === false){
            return false;
        }
        elseif(is_array($list)){
            $this->reset(true);
            $this->data($record);
            if(empty($list)){
                return false;
            }
            return array_shift($list);
        }
        return $record;
    }

    /**
     * @throws Exception
     */
    public function where($where=[]): mixed
    {
        $type = $this->type();
        switch($type){
            case 'list' :
                return $this->where_list($where);
            case 'record' :
                return $this->where_record($where);
            default :
                throw new Exception('Unknown type: ' . $type);
        }
    }
}