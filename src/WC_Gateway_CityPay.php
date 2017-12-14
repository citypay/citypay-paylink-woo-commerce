<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_CityPay extends WC_Payment_Gateway
{
    public $debug = 'no';
    public $testmode = 'no';
    public $log;

    public function __construct()
    {
        $this->enabled = $this->get_option('enabled');
        $this->debug = $this->get_option('debug');
        $this->testmode = $this->get_option('testmode');
        $this->log = new WC_Logger();
    }

    /* Check if the module is available for the current checkout process */
    public function is_available()
    {
        return $this->enabled === "yes";
    }

    function debugLog($text)
    {
        if ('yes' == $this->debug) {
            $this->log->debug($text, array('source' => $this->id));
        }
    }

    function infoLog($text)
    {
        $this->log->info($text, array('source' => $this->id));
    }

    function errorLog($text)
    {
        $this->log->error($text, array('source' => $this->id));
    }

    function warningLog($text)
    {
        $this->log->warning($text, array('source' => $this->id));
    }

}


?>