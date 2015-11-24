<?php
namespace HKP;

# HTTP_HOST
defined('HTTP_HOST') || define('HTTP_HOST', (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost'));

# URL_ROOT
if (isset($_SERVER['PHP_SELF'])) {
    $_SELF = dirname($_SERVER['PHP_SELF']);
    if (substr($_SELF, -1) == '/' || substr($_SELF, -1) == '\\') $_SELF = substr($_SELF, 0, -1);
    defined('URL_ROOT') || define('URL_ROOT', 'http://' . HTTP_HOST . $_SELF);
} else {
    defined('URL_ROOT') || define('URL_ROOT', 'http://' . HTTP_HOST);
}

# COOKIE_DOMAIN
if (!defined('COOKIE_DOMAIN')) {
    $domain = explode('.', HTTP_HOST);
    $domain = (count($domain) == 1) ? $domain[0] : implode('.', array_slice($domain, -2));
    define('COOKIE_DOMAIN', $domain);
}

# auth verify hook function
defined('AUTH_HOOK') || define('AUTH_HOOK', null);

defined('DEBUG') || define('DEBUG', true);
defined('ROUTE_LOG_FILE') || define('ROUTE_LOG_FILE', '/tmp/hk_route.log');
defined('PATH_ROOT') || define('PATH_ROOT', __DIR__);
defined('PATH_ACTION') || define('PATH_ACTION', PATH_ROOT . '/action');
defined('PATH_VIEW') || define('PATH_VIEW', PATH_ROOT . '/view');
defined('PATH_LIB') || define('PATH_LIB', PATH_ROOT . '/lib');

error_reporting(0);
ini_set('date.timezone', 'Asia/Shanghai');

class ROUTE extends \Exception
{
    /**
     * 是否是命令行模式
     *
     * @var bool
     */
    static public $cli = false;

    /**
     * 当前进程中加载的File
     *
     * @var array
     */
    static protected $loadFiles = [];

    static protected $_ACTION_ = 'index';
    static protected $_FUNCTION_ = 'index';

    /**
     * 开始执行
     */
    static public function run()
    {
        register_shutdown_function(array('\HKP\ErrorMsg', 'check_for_fatal'));
        set_error_handler(array('\HKP\ErrorMsg', 'myErrorHandler'));
        spl_autoload_register(get_called_class() . '::autoLoad');

        // cli
        self::$cli = (php_sapi_name() == 'cli');
        if (self::$cli) {
            global $argv;
            $_SERVER = [
                'REMOTE_ADDR' => '127.0.0.1',
                'SCRIPT_NAME' => __FILE__,
                'REQUEST_URI' => str_replace('/' . basename(__FILE__), '', __FILE__) . $argv[1],
            ];
        }

        # 普通请求
        // [SCRIPT_NAME] => /a/b/index.php
        // [REQUEST_URI] => /a/b/c/d
        $str = substr($_SERVER['REQUEST_URI'], strlen(dirname($_SERVER['SCRIPT_NAME'])));
        if ($str[0] == '/') $str = substr($str, 1);
        $str = parse_url($str);
        if (isset($str['path'])) $str = $str['path'];
        else $str = '';
        if (strlen($str) > 0 && $str[strlen($str) - 1] == '/') $str = substr($str, 0, -1);
        $str = explode('/', $str);
        if (isset($str[0]) && $str[0] == '') unset($str[0]);
        # dispatch
        try {
            call_user_func_array(get_called_class() . '::dispatch', $str);
        } catch (\PDOException $e) {
            ErrorMsg::outErr($e->getCode(), $e->getMessage(), $e->getFile(), $e->getLine(), func_get_args());
        } catch (ErrorMsg $e) {
            ErrorMsg::outErr($e->getCode(), $e->getMessage(), $e->getFile(), $e->getLine(), func_get_args());
            //self::outJson($e->getCode(), $e->getMessage());
        } catch (\Exception $e) {
            $no = $e->getCode() == 0 ? 400 : $e->getCode();
            ErrorMsg::outErr($no, $e->getMessage(), $e->getFile(), $e->getLine(), func_get_args());
        }
    }

    /**
     * 路由URL
     *
     * @param string $action 模块
     * @param string $fun    执行代码
     *
     * @return bool
     */
    static public function dispatch($action = 'index', $fun = 'index')
    {
        $args = func_get_args();
        array_splice($args, 0, 2);
        $params = $args;
        self::$_ACTION_ = $action;
        self::$_FUNCTION_ = $fun;

        // auth hook
        if (defined('AUTH_HOOK') && AUTH_HOOK && !self::$cli) {
            call_user_func_array(AUTH_HOOK, $args);
        }

        $phpFile = PATH_ACTION . '/' . strtolower($action) . '.php';
        $viewFile = PATH_VIEW . '/' . strtolower($action) . '/' . strtolower($fun) . '.php';
        if (file_exists($phpFile)) {
            self::inc($phpFile);
            $_action = self::ab_cd2AbCd($action) . 'Action';
            if (method_exists($_action, $fun)) {
                return call_user_func_array([$_action, $fun], $params);
            }
        }
        if (file_exists($viewFile)) {
            return self::inc($viewFile);
        }
        echo 404, '. ', $action, '::', $fun;
        return false;
    }

    static public function ab_cd2AbCd($str)
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $str)));
    }

    static public function AbCd2ab_cd($str)
    {
        return strtolower(preg_replace('/([A-Z])/', '_$1', lcfirst($str)));
    }

    static public function autoLoad($clsName)
    {
        if (substr($clsName, '-3') == 'Lib') {
            $f = substr($clsName, 0, -3);
            $f = self::AbCd2ab_cd($f);
            $file = PATH_LIB . '/' . $f . '.php';
            if (file_exists($file)) {
                self::inc($file);
            }
        } else if (substr($clsName, '-6') == 'Action') {
            $f = substr($clsName, 0, -6);
            $f = self::AbCd2ab_cd($f);
            $file = PATH_ACTION . '/' . $f . '.php';
            if (file_exists($file)) {
                self::inc($file);
            }
        }
        return false;
    }

    /**
     * 引用文件
     *
     * @param string $file 文件绝对路径
     *
     * @return array|bool|mixed
     */
    static protected function inc($file)
    {
        if (isset(self::$loadFiles[$file])) return true;
        self::$loadFiles[$file] = 1;
        return require $file;
    }

    ##### OUTPUT #####

    /**
     * 输出JSON数据
     *
     * @param int   $no      错误代码
     * @param mixed $data    数据内容
     * @param int   $options 如：JSON_PRETTY_PRINT=128 格式化输出
     *
     * @return mixed
     */
    static public function outJson($no, $data, $options = JSON_PRETTY_PRINT)
    {
        if (self::$cli) {
            print_r(['no' => $no, 'data' => $data]);
        } else {
            echo json_encode(['no' => $no, 'data' => $data], $options);
        }
        exit;
    }

    static public function retJson($no, $data)
    {
        return ['no' => $no, 'data' => $data];
    }

    /**
     * 渲染模版
     *
     * @param string $tpl      模版相对路径
     * @param array  $data     模版需要的数据
     * @param string $tplFrame 模版框架
     *
     * @return bool
     */
    static public function render($tpl, $data = [], $tplFrame = 'frame_default.php')
    {
        extract($data);
        if ($tpl[0] != '/') $tpl = '/' . $tpl;
        $_TPL_TPL_ = PATH_VIEW . $tpl;
        $_FRAME_TPL_ = PATH_VIEW . '/public/' . $tplFrame;
        if (file_exists($_FRAME_TPL_)) require $_FRAME_TPL_;
        else if (file_exists($_TPL_TPL_)) require $_TPL_TPL_;
        else die('Not found template file: ' . $tpl);
        return true;
    }

    static public function redirect($url, $absolute = false)
    {
        $url = ($absolute ? '' : URL_ROOT) . $url;
        header('Location: ' . $url);
        echo "<script>location.href='{$url}';</script>";
        exit;
    }
}

class ErrorMsg extends \Exception
{
    static public function myErrorHandler()
    {
        call_user_func_array(get_called_class() . '::outErr', func_get_args());
        exit;
    }

    static public function check_for_fatal()
    {
        $error = error_get_last();
        if ($error["type"] == E_ERROR) {
            self::outErr($error["type"], $error["message"], $error["file"], $error["line"], func_get_args());
        }
    }

    static public function outErr($no, $msg, $file, $line, $arr = [])
    {
        if (defined('LOG_HOOK')) {
            call_user_func_array(LOG_HOOK, func_get_args());
        }

        if (!file_exists(ROUTE_LOG_FILE)) {
            touch(ROUTE_LOG_FILE);
            chmod(ROUTE_LOG_FILE, 0777);
        }

        if (is_writeable(ROUTE_LOG_FILE) && !ROUTE::$cli) {
            $log = "[" . date('Y-m-d H:i:s') . "] [$no] $msg @ $file($line) " . json_encode($arr) . "\n";
            file_put_contents(ROUTE_LOG_FILE, $log, FILE_APPEND);
        } else {
            echo json_encode([
                'no'        => $no,
                'isSuccess' => 0,
                'php_errno' => $no,
                'data'      => [$no, $msg, $file, $line]
            ], JSON_PRETTY_PRINT);
        }
        exit;
    }
}