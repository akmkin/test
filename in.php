<?php

function crawlUrlList() 
{
    $file_handle = fopen("urls.txt", "r");
    while (!feof($file_handle)) 
    {
       crawlUrl(fgetss($file_handle));
    }
    fclose($file_handle);
}

function crawlUrl($url) 
{
    $ch = curl_init();
    $pageNumber = 0;

    do 
    {
        $urlWithPage = trim($url) . '&page=' . ++$pageNumber;
        print $urlWithPage . PHP_EOL;

        curl_setopt($ch, CURLOPT_URL, $urlWithPage); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
        do 
        {
            sleep(rand(1,2));
            echo 'CURL EXEC' . PHP_EOL;
            $output = curl_exec($ch);    
        } 
        while (strpos($output,'Robot Check') > 0);
         
        $re = '/data-asin=\"(.{10})\" class=\"s-result-item/';    
        preg_match_all($re, $output, $matches, PREG_SET_ORDER, 0);
        
        foreach ($matches as $match) 
        {
            // print $match[1] . PHP_EOL;
            $data = AmazonParse::parse($match[1]);
            if($data !== false)
            {
                AmazonParse::addToCsv($data);
            }
            else
            {
                //print ASIN in error log
                print '<br /><span style="color:red">'.$match[1].'</span><br />';
            }
        }
        
    }
    while (count($matches) > 0 );

    if (strpos($output,'Your search') === false) 
    {
        var_dump(curl_error($ch));
        var_dump(curl_getinfo($ch));
        echo $output;
    }
    curl_close($ch);
}

crawlUrlList();

class AmazonParse
{
    public function parse($match)
    {
        sleep(rand(1,2));
        $access_key = '';//ACCESS_KEY
        $secure_access_key = '';//SECURE_KEY
        $associatetag = '';//ASSOTIATE_ID
        $info = self::getInfo($match, $access_key, $secure_access_key, $associatetag);
        return $info;
    }
 
    function getInfo($match, $access_key, $secure_access_key, $associatetag)
    {
        $fields = array();
        $fields['Service'] = 'AWSECommerceService';
        $fields['AWSAccessKeyId'] = $access_key;
        $fields['AssociateTag'] = $associatetag;
        $fields['Operation'] = 'ItemLookup';
        $fields['ItemId'] = $match;
        $fields['SignatureMethod']  = 'HmacSHA256'; 
        $fields['ResponseGroup'] = 'Request,Large';
        $fields['Timestamp'] = gmdate('Y-m-d\TH:i:s\Z');

        ksort($fields);

        $query = array();
        foreach ($fields as $key=>$value) 
        {
            $query[] = "$key=" . urlencode($value);
        }

        $string = "GET\nwebservices.amazon.in\n/onca/xml\n" . implode('&', $query);
        $signed = urlencode(base64_encode(hash_hmac('sha256', $string, $secure_access_key, true)));

        $url = 'https://webservices.amazon.in/onca/xml?' . implode('&', $query) . '&Signature=' . $signed;

        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, $url);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, 1);
        $data = curl_exec($ch2);
        $info = curl_getinfo($ch2);
        curl_close($ch2);

        if ($info['http_code'] != '200') 
            return false;

        return $data;
    }

    function parseAmazonXml($info)
    {
        $xml = simplexml_load_string($info);
        $arr = array();
        $arr[0] = $xml->Items->Item->ItemAttributes->Manufacturer;
        $arr[1] = $xml->Items->Item->ItemAttributes->PartNumber;
        $arr[2] = $xml->Items->Item->ItemAttributes->Title;
        $arr[3] = $xml->Items->Item->ASIN;
        $arr[4] = (int)str_replace(',', '', $xml->Items->Item->ItemAttributes->ListPrice->FormattedPrice);
        $arr[5] = $xml->Items->Item->DetailPageURL;
        return $arr;
    }

    function addToCsv($info)
    {
        $fp = fopen('csv.csv', 'r');
        if(fgetss($fp) == '')
        {
            self::writeRows();
        }
        fclose($fp);

        $arr = self::parseAmazonXml($info);

        $fp = fopen('csv.csv', 'a');
        self::myFputcsv($fp, $arr);
        fclose($fp);
    }

    function writeRows()
    {
        $names = array(
            'Brand', 
            'Part number', 
            'Name', 
            'ASIN', 
            'Price (INR)', 
            'URL'
        );

        $fp = fopen('csv.csv', 'w');
        fputs($fp, $bom =( chr(0xEF) . chr(0xBB) . chr(0xBF) ));
        self::myFputcsv($fp, $names);
        fclose($fp);
    }

    function  myFputcsv($fp, $csv_arr, $delimiter = ';', $enclosure = '"')
    {
        if (!is_array($csv_arr))
        {
            return(false);
        }
        for ($i = 0, $n = count($csv_arr); $i < $n;  $i ++)
        {
            if (!is_numeric($csv_arr[$i]))
            {
                $csv_arr[$i] =  $enclosure.str_replace($enclosure, $enclosure.$enclosure,  $csv_arr[$i]).$enclosure;
            }
            if (($delimiter == '.') && (is_numeric($csv_arr[$i])))
            {
                $csv_arr[$i] =  $enclosure.$csv_arr[$i].$enclosure;
            }
        }
        $str = implode($delimiter,  $csv_arr).PHP_EOL;
        fwrite($fp, $str);
        return strlen($str);
    }
}
?>