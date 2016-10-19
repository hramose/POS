<?php
/**
 * Created by PhpStorm.
 * User: miftah.fathudin
 * Date: 10/18/2016
 * Time: 3:11 AM
 */

namespace App\Util;


/**
 * Class POCodeGenerator
 *
 * A utility class to generate a random alphanumeric string for PO code.
 *
 * @package App\Util
 */
class POCodeGenerator implements StringGenerator
{

    /**
     * @param $length number of the generated string
     * @return string generated string with specified length
     */
    public static function generateWithLength($length)
    {
        $generatedString = '';
        $characters = array_merge(range('a', 'z'), range(0,9));
        $max = sizeof($characters) - 1;
        for ($i = 0; $i < $length; $i++) {
            $generatedString .= $characters[mt_rand(0, $max)];
        }
        return strtoupper($generatedString);
    }
}