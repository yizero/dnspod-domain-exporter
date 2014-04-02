<?php
/**
 * 将 DNSPod 国内版的域名导入到国际版
 *
 * @ Yizero (yizerowu@dnspod.com)
 * @ 2014-04-02 13:55:51
 * @ Version 0.0.1
 **/

/*------------- BEGIN 请配置帐号、密码等信息 BEGIN ------------*/

//DNSPod 国内版帐号
define('CN_USER', '');
//DNSPod 国内版密码
define('CN_PASSWORD', '');
//DNSPod 国内版 D令牌 验证码, 如果没有开启 D令牌，留空即可
define('CN_DTOKEN', '');

//DNSPod 国际版帐号
define('COM_USER', '');
//DNSPod 国际版密码
define('COM_PASSWORD', '');

/*------------- END 请配置帐号、密码等信息 END -----------------*/


define('COM_API_URL', 'https://www.dnspod.com/api/');
define('CN_API_URL', 'https://dnsapi.cn/');
define('LOG_FILE', 'export_domain.log');

set_time_limit(0);
$FileLog    = new FileLog(LOG_FILE);

try {
    $DNSPodCN   = new DNSPodCN(CN_USER, CN_PASSWORD, CN_DTOKEN);
    $DNSPodCom  = new DNSPodCOM(COM_USER, COM_PASSWORD);
    $domains    = $DNSPodCN->getDomains();

} catch (Exception $e) {
    die('Initialization Exception: ' . $e->getMessage() . "\n");
}

foreach ($domains as $domain) {
    $FileLog->newLine("Adding domain: {$domain};");

    try {
        $ret    = $DNSPodCom->addDomain($domain);
        $ret    = (true === $ret)? $FileLog->append(' Result: success') : $FileLog->append(" Result: error ({$ret})");
    } catch (Exception $e) {
        $FileLog->append(' Exception: ' . $e->getMessage());
    }

    $records    = $DNSPodCN->getRecords($domain);

    if ( is_array($records) && !empty($records) ) {
        foreach ($records as $k => $v) {
            $FileLog->newLine("   Adding record: {$v['sub_domain']}, {$v['record_type']}, {$v['value']}, {$v['ttl']}, {$v['mx']};");

            try {
                $ret    = $DNSPodCom->addRecord($v['domain'], $v['sub_domain'], $v['record_type'], $v['value'], $v['ttl'], $v['mx']);
                $ret    = (true === $ret)? $FileLog->append(' Result: success') : $FileLog->append(" Result: error({$ret})");
            } catch (Exception $e) {
                $FileLog->append(' Exception: ' . $e->getMessage());
            }
        }

    } else {
        $FileLog->newLine("    Domain has no records");
    }

    $FileLog->newLine("\n===============================\n");
}



class DNSPodCOM {
    protected $user_email;
    protected $user_password;
    protected $mario;

    public function DNSPodCOM($user_email, $user_password) {
        $this->user_email       = $user_email;
        $this->user_password    = $user_password;
        $this->login();
    }


    /**
     * 国际版，添加一个域名
     *
     **/
    public function addDomain($domain) {
        $params = array('domain' => $domain);
        $ret    = Http::request(COM_API_URL.'domains', 'post', json_encode($params), "mario={$this->mario}", true);

        if ( isset($ret['domain_id']) && $ret['domain_id'] > 0 ) {
            return true;

        } else { 
            return isset($ret['error'])? $ret['error'] : 'unknow error';
        } 
    }


    /**
     * 国际版，给域名添加一条记录
     *
     **/
    public function addRecord($domain, $sub_domain, $record_type, $value, $ttl = 600, $mx = 5) {
        $params = array(
            'area'          =>  "0",
            'sub_domain'    => $sub_domain,
            'record_type'   => $record_type,
            'value'         => $value,
            'mx'            => $mx,
            'ttl'           => $ttl,
        );
        $ret    = Http::request(COM_API_URL.'records/'.$domain, 'post', json_encode($params), "mario={$this->mario}", true);
        if ( count($ret) == count($params) ) {
            return true;
        } else {
            return isset($ret['error'])? $ret['error'] : 'unknow error';
        }
    }


    private function login() {
        if ( $this->mario ) {
            return $this->mario;
        }

        $params = array(
            'email'     => $this->user_email, 
            'password'  => $this->user_password
        );

        $ret    = Http::request(COM_API_URL . 'auth?' . http_build_query($params), 'get');

        if ( isset($ret['mario']) ) {
            $this->mario = $ret['mario'];

        } else {
            throw new Exception("dnspod.com login failed: {$ret['error']}");
        }
    }
}



class DNSPodCN {
    protected $base_params;
    protected $login_code;
    protected $login_code_cookie;

    public function DNSPodCN($user_email, $user_password, $login_code=null) {
        $this->base_params      = array(
            'login_email'       => $user_email,
            'login_password'    => $user_password,
            'format'            => 'json',
        );

        $this->login_code           = $login_code;
        $this->login_code_cookie    = null;
        $this->login();
    }


    /**
     * 国内版，获取所有域名，不包含共享活得的域名
     *
     **/
    public function getDomains() {
        $page       = 0;
        $page_size  = 200;
        $domains    = array();

        while ( true ) {
            $offset = (++$page - 1) * $page_size;
            $params = array_merge($this->base_params, array(
                'type'      => 'mine',
                'offset'    => $offset,
                'length'    => $page_size,
            ));

            $ret    = Http::request(CN_API_URL.'Domain.List', 'post', $params, $this->login_code_cookie);
            if ( isset($ret['status']['code']) && $ret['status']['code'] == 1 ) {
                foreach ($ret['domains'] as $k=> $v) {
                    $domains[]  = $v['name'];
                }
            
            } else {
                break;
            }
        }

        if ( empty($domains) ) {
            throw new Exception('No domians under your account');
        }

        return $domains;
    }


    /**
     * 国内版，获取域名记录，不包含 DNSPod 的默认 NS 记录
     *
     **/
    public function getRecords($domain) {
        $page       = 0;
        $page_size  = 1000;
        $records    = array();

        $_dnspod_ns = array(
            'f1g1ns1.dnspod.net.', 'f1g1ns2.dnspod.net.', 
            'ns1.dnsv2.com.', 'ns2.dnsv2.com.', 
            'ns1.dnsv3.com.', 'ns2.dnsv3.com.', 
            'ns1.dnsv4.com.', 'ns2.dnsv4.com.', 
            'ns1.dnsv5.com.', 'ns2.dnsv5.com.',
            'ns3.dnsv2.com.', 'ns4.dnsv2.com.', 
            'ns3.dnsv3.com.', 'ns4.dnsv3.com.', 
            'ns3.dnsv4.com.', 'ns4.dnsv4.com.', 
            'ns3.dnsv5.com.', 'ns4.dnsv5.com.',
            'ns3.dnsv3.com.', 'ns4.dnsv3.com.',
        );

        while ( true ) {
            $offset = (++$page - 1) * $page_size;
            $params = array_merge($this->base_params, array(
                'domain'    => $domain,
                'offset'    => $offset,
                'length'    => $page_size,
            ));

            $ret    = Http::request(CN_API_URL.'Record.List', 'post', $params, $this->login_code_cookie);
            if ( isset($ret['status']['code']) && $ret['status']['code'] == 1 ) {
                foreach ($ret['records'] as $k=> $v) {
                    if ( $v['type'] == 'NS' && in_array($v['value'], $_dnspod_ns) ) continue;
                    $records[]  = array(
                        'domain'        => $domain,
                        'sub_domain'    => $v['name'],
                        'record_type'   => $v['type'],
                        'value'         => $v['value'],
                        'ttl'           => $v['ttl'],
                        'mx'            => $v['mx'],
                    );
                }
            
            } else {
                break;
            }
        }

        return $records;
    }


    private function login() {
        $ret    = Http::request(CN_API_URL.'User.Detail', 'post', $this->base_params);

        if ( isset($ret['status']['code']) && $ret['status']['code'] == 50 ) {
            $params = array_merge($this->base_params, array(
                'login_remember'    => 'yes',
                'login_code'        => $this->login_code,
            ));

            $ret    = Http::request(CN_API_URL.'User.Detail', 'post', $params, null, false, true);
            preg_match_all('|Set-Cookie: (.*);|U', $ret, $m);


            if ( isset($m[1][1]) ) {
                $this->login_code_cookie    = $m[1][1];
                return true;

            } else {
                throw new Exception("D-Token code error? I can not get the D-Token cookie");
            }

        } else {
            if (isset($ret['status']['code']) && $ret['status']['code'] == 1) {
                return true;
            } else {
                $error_msg  = isset($ret['status']['message'])? $ret['status']['message'] : 'unknow error';
                throw new Exception($error_msg);
            }
        }
    }
}



class Http {
    public static function request($url, $action = 'post', $params=array(), $cookie=null, $json_header=false, $return_header=false) {
        if( !function_exists('curl_init') ) {
            die('Need to open the curl extension');
        }

        $ci = curl_init();
        curl_setopt($ci, CURLOPT_URL, $url);
        curl_setopt($ci, CURLOPT_USERAGENT, 'dnspod_export_from_cn_to_com(V0.0.1)/yizerowu@dnspod.com');
        curl_setopt($ci, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ci, CURLOPT_TIMEOUT, 30);
        curl_setopt($ci, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ci, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ci, CURLOPT_SSL_VERIFYHOST, false);

        if ( $return_header ) {
            curl_setopt($ci, CURLOPT_HEADER, 1);
        }

        if ( $action == 'post' ) {
            curl_setopt($ci, CURLOPT_POST, TRUE);

            if ( $json_header) curl_setopt($ci, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            if ( !empty($params) ) curl_setopt($ci, CURLOPT_POSTFIELDS, $params);

        } elseif ( $action == 'get' ) {
            curl_setopt($ci, CURLOPT_HTTPGET, TRUE);
        
        } else {
            throw new Exception('ACTION is not support');
        }

        if ( $cookie ) curl_setopt($ci, CURLOPT_COOKIE, $cookie);

        $response   = curl_exec($ci);

        if (curl_errno($ci)) {
            throw new Exception('CURL get error:' . curl_error($ci));
        }

        // if the response with header, return all the string, no need json_decode
        if ( $return_header ) {
            return $response;
        }

        $ret    = json_decode($response, true);
        if ( !$ret ) {
            throw new Exception('response is error, can not be json decode: ' . $response);
        }

        return $ret;
    }
}

class FileLog {
    protected $log_file;

    public function FileLog($log_file) {
        $this->log_file = $log_file;
    }

    public function newLine($string) {
        file_put_contents($this->log_file, "\n{$string}", FILE_APPEND);
    }

    public function append($string) {
        file_put_contents($this->log_file, $string, FILE_APPEND);
    }
}

?>
