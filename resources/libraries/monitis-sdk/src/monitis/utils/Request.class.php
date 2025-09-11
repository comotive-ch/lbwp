<?php
/*
 * Send GET and POST requests
 */
class Request{
    const GET=0;
    const POST=1;
    
    private $url;
    private $method;
    private $fields;
    private $query;
    
    public function __construct($url, $fields = null, $method = 0){
        $this->setUrl($url);
        $this->setFiels($fields);
        $this->setMethod($method);
    }
    
    public function send(){
        if(function_exists('curl_init')){
            $curl = curl_init();
            
            if($this->method==self::GET){
                curl_setopt($curl, CURLOPT_URL, $this->url .'?'. $this->query);
            }
            else{
                curl_setopt($curl, CURLOPT_URL, $this->url);
                curl_setopt($curl, CURLOPT_POST, count($this->fields));
                curl_setopt($curl, CURLOPT_POSTFIELDS, $this->query);
            }
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            
            $response = curl_exec($curl);
            curl_close($curl);
        }
        else{
            if($this->method == self::GET){
                $context = stream_context_create(array(
                    "http" => array(
                        "method" => "GET",
                        "header" => ""
                    )
                ));
                $url = $this->url. '?' . $this->query;
            }
            else{
                $context = stream_context_create(array(
                    "http" => array(
                        "method" => "POST",
                        "header" => "Content-Type: application/x-www-form-urlencoded\r\n" .
                        "Content-Length: ". strlen($this->query) . "\r\n",
                        "content" => $this->query
                    )
                ));
                $url = $this->url;
            }
            $response = file_get_contents($url, false, $context);
        }
        return $response;
    }
    
    public function getUrl(){
        return $this->url;
    }
    
    public function getFields(){
        return $this->fields;
    }
    
    public function getMethod(){
        return $this->method;
    }
    
    public function getQuery(){
        return $this->query;
    }
    
    public function setUrl($url){
        $this->url = $url;
    }
    
    public function setMethod($method = 0){
        $this->method = $method;
    }
    
    public function setFiels($fields){
        $this->fields = $fields;
        $this->query = http_build_query($this->fields);
    }
}
?>