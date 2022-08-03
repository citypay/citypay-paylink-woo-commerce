<?php

class ApiKey
{

    private $clientId;
    private $licenceKey;

    /**
     * ApiKey constructor.
     * @param $clientId
     * @param $licenceKey
     */
    public function __construct($clientId, $licenceKey)
    {
        $this->clientId = $clientId;
        $this->licenceKey = $licenceKey;
    }

    /**
     * Generates a new key statically
     * @param $clientId string the client id to use
     * @param $licenceKey string the licence key to use
     * @return string of the new key
     * @throws \Exception
     */
    public static function newKey($clientId, $licenceKey)
    {
        $key = new ApiKey($clientId, $licenceKey);
        return $key->generate();
    }


    /**
     * Generates a new key with a random value and the current system data time in UTC
     * @throws \Exception
     */
    public function generate()
    {
        return self::generateWith(bin2hex(random_bytes(16)), new \DateTime("now", new \DateTimeZone("UTC")));

    }

    /**
     * Generates a new key with a given random value and given date time
     * @param string $nonce a 128 bit (16 byte) random value
     * @param \DateTime $dt a UTC date time value
     * @return string a base64 encoded temporal key
     */
    public function generateWith(string $nonce, \DateTime $dt)
    {
        $ds = $dt->format("YmdHi");
        $data = array_merge(
            unpack('C*', $this->clientId),
            unpack('C*', hex2bin($nonce)),
            unpack('C*', hex2bin($ds))
        );

        $digest = unpack('C*', hash_hmac("sha256", pack("c*", ...$data), $this->licenceKey, true));

//        print "\nds: " . $ds;
//        print "\nclientid: " . $this->clientId;
//        print "\nlicenceKey: " . $this->licenceKey;
//        print "\nnonce: " . $nonce;
//        print "\nData: ";
//        for ($i = 0; $i < count($data); $i++) {
//            print $data[$i] . ' ';
//        }
//        print (pack("c*", ...$data));
//        print "\nDigest: ";
//        for ($i = 0; $i < count($digest); $i++) {
//            print $digest[$i] . ' ';
//        }

        $dest = array_merge(
            unpack('C*', $this->clientId),
            unpack('C*', "\x3A"),
            unpack("C*", strtoupper($nonce)),
            unpack('C*', "\x3A"),
            $digest
        );

        return base64_encode(pack("c*", ...$dest));
    }


}


