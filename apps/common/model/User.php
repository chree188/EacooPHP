<?php
namespace app\common\model;
use app\admin\model\AuthGroupAccess;

class User extends Base
{
	// 设置数据表（不含前缀）
    protected $name = 'users';

    // 定义时间戳字段名
    protected $createTime = 'reg_time';
    protected $updateTime = '';
    // 自动完成
    protected $auto       = ['last_login_ip'];
    protected $insert     = ['register_ip','status' => 2,'password'];
    //protected $update     = ['password'];

    public function setRegisterIpAttr($value)
    {
        return request()->ip();
        
    }

    public function setPasswordAttr($value)
    {
        if (!empty($value)) {
            return encrypt($value);
        } else{
            return $value;
        }
        
    }

    public function getStatusTextAttr($value,$data)
    {
        $status = [ 1 => '正常', -1 => '删除', 0 => '禁用', 2 => '待审核', 3 => '草稿'];
        return isset($status[$data['status']]) ? $status[$data['status']] : '未知';
    }

    public function getSexTextAttr($value,$data)
    {
        $sex = [ 0 => '保密', 1 => '男', 2 => '女'];
        return isset($sex[$data['sex']]) ? $sex[$data['sex']] : '未知';
    }

    /**
     * 用户信息
     * @param  integer $uid [description]
     * @return [type]       [description]
     */
    public static function info($uid = 0)
    {
       if (!$uid) return false;
       $result = cache('User_info_'.$uid);
       if (!$result || empty($result)) {
            $info = self::get($uid);
            if ($info) {
                $info           = $info->toArray();
                $info['avatar'] = path_to_url($info['avatar']);
                $result         = self::extendInfo($info);
                $result         = array_merge($info,$result);
                cache('User_info_'.$uid, $result,3600);
            }
       }

       return $result;
    }

    /**
     * 置扩展信息
     * @param  array  $result [description]
     * @return [type]         [description]
     */
    protected static function extendInfo($result=[])
    {
        $result['auth_group'] = model('admin/auth_group_access')->userGroupInfo($result['uid']);
        return $result;
    }

    /**
     * 注册用户
     * @param  array   $data   注册信息
     * @param  boolean $strict 是否严格
     * @return [type]          [description]
     */
    public static function register($data =[],$strict=true)
    {
        if (!empty($data)) {
            $check = true;
            if ($strict==true) {
                $check = self::checkDenyUser($data['username']);
            }
            //通过检测
            if ($check) {
               return self::create($data);
            }
            
        }
        return false;
    }

    /**
     * 检测用户名是不是被禁止注册
     * @param  string $username 用户名
     * @return boolean ture 未禁用，false 禁止注册
     */
    protected static function checkDenyUser($username){
        if ($username) {
            $deny = config('user_deny_username');
            $deny = explode ( ',', $deny);
            foreach ($deny as $k=>$v) {
                if(stristr($username, $v)){
                    return false;
                }
            }
            return true;
        } 
        return false;
    }

     /**
     * 用户登录
     * @param  string  $login  登录
     * @param  string  $password 用户密码
     * @param  int     $type     登录类型 （1-用户编号, 2-用户账户, 3-手机, 4-用户昵称, 5-用户邮件, 6-全部）
     * @return int               [登录成功-用户ID，登录失败-错误编号]
     * @param bool $rememberme 记住登录
     */
    public function login($login, $password, $rememberme = false, $type = 6){
        $map = '';
        switch ($type) {
            case 1:
                $map = 'number';
                break;
            case 2:
                $map = 'username';
                break;
            case 3:
                $map = 'mobile';
                break;
            case 4:
                $map = 'nickname';
                break;
            case 5:
                $map = 'email';
                break;
            case 6:
                $map = 'username|mobile|nickname|email';
                break;
            default:
            	$this->error = '参数错误';
                return false; //参数错误
        }

        /* 获取用户数据 */
        $user = $this->whereOr([$map => $login,'status'=>1])->find();

        if($user){   
            /* 验证用户密码 */
            if(encrypt($password) === $user['password']){
                self::autoLogin($user,$rememberme); //更新用户登录信息
                return $user['uid']; //登录成功，返回用户ID
            } else {
                $this->error = '密码错误！';
            }
        } else {
        	$this->error = '用户不存在或被禁用！';
        }

        return false;
    }

    /**
     * 自动登录
     * @param  [type]  $user       用户对象
     * @param  boolean $rememberme 是否记住登录，默认7天
     * @return [type]              [description]
     */
    public static function autoLogin($user, $rememberme = false){
        if (empty($user)) return false;

        // 记录登录SESSION和COOKIES
        $auth_login = [
            'uid'             => $user['uid'],
            'username'        => $user['username'],
            'nickname'        => $user['nickname'],
            'email'           => $user['email'],
            'mobile'          => $user['mobile'],
            'avatar'          => $user['avatar'],
            'auth_group'      => model('admin/auth_group_access')->userGroupInfo($user['uid']),
            'reg_time'        => $user['reg_time'],
            'last_login_time' => time()
        ];

        $auth_login_sign = data_auth_sign($auth_login);
        session('user_login_auth', $auth_login);
        session('activation_auth_sign', $auth_login_sign);
        // 更新登录信息
        $data = [
            'last_login_ip'        => request()->ip(),
            'last_login_time'      => $auth_login['last_login_time'],
            'activation_auth_sign' => $auth_login_sign,
        ];

        self::where(['uid' => $auth_login['uid']])->update($data);

        // 记住登录
        if ($rememberme) {
            $signin_token = $user['username'].$user['uid'].$user['last_login_time'];
            cookie('uid', $user['uid'], 24 * 3600 * 7);
            cookie('signin_token', data_auth_sign($signin_token), 24 * 3600 * 7);
        }
    }

     /**
     * 检测用户信息
     * @param  string  $field  用户名
     * @param  integer $type   用户名类型 1-用户名，2-用户邮箱，3-用户电话
     * @return integer         错误编号
     */
    public function checkField($findField, $where, $returnField, $check = false){
        if ($check) {
            // 根据字段得到用户相关索引ID
            return $this->where($findField, $where)->value($returnField);
        } else {
            // 根据字段检测是否存在此用户
            return $this->where($findField, $where)->count();
        }
    }

    /**
     * 更新登录用户的session
     * @return void
     */
    public function updateLoginSession($uid){

        if ($uid == is_login()) {            
            $user        = self::get($uid);

            $auth_login = [
                'uid'             => $user['uid'],
                'username'        => $user['username'],
                'nickname'        => $user['nickname'],
                'email'           => $user['email'],
                'mobile'          => $user['mobile'],
                'avatar'          => $user['avatar'],
                'auth_group'      => model('admin/auth_group_access')->userGroupInfo($user['uid']),
                'reg_time'        => $user['reg_time'],
                'last_login_time' => time()
            ];
            $activation_auth_sign = data_auth_sign($auth_login);
            session('user_login_auth', $auth_login);            
            session('activation_auth_sign',$activation_auth_sign);

            return $this->where('uid',$uid)->update(['activation_auth_sign' => $activation_auth_sign]);
        }
        return false;
    }

    /**
     * 判断是否登录
     * @return int 0或用户id
     */
    public static function isLogin()
    {
        $user = session('user_login_auth');
        if (empty($user)) {
            // 判断是否记住登录
            if (cookie('?uid') && cookie('?signin_token')) {
                $user = self::get(cookie('uid'));
                if ($user) {
                    $signin_token = data_auth_sign($user->username.$user->uid.$user->last_login_time);
                    if (cookie('signin_token') == $signin_token) {
                        // 自动登录
                        self::autoLogin($user, true);
                        return $user->uid;
                    }
                }
            };
            return 0;
        } else{
            return session('activation_auth_sign') == data_auth_sign($user) ? $user['uid'] : 0;
        }
    }

}