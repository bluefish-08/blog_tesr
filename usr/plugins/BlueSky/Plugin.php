<?php
namespace TypechoPlugin\BlueSky;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Common;
use Widget\Options;
use Widget\Upload;
use CURLFile;

if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * LskyPro企业版图床插件(理论上兼容开源版[未测试]),要求Typecho1.2及以上版本
 *
 * @package BlueSky
 * @author 泽泽社长
 * @version 1.0.5
 * @link https://store.typecho.work
 */
 
//感谢 莫言,兔子君,冷寂 等网友制作或修改的优化版本
//https://qzone.work/codes/725.html
//https://www.tuzijun.cn
//https://www.coldyun.cn/archives/140.html

//插件名称小故事：
/*小学学前班阶段时父母除外打工，把我扔在了大姑家，另外一个村子距离我家很远的,
于是就在那边上学，学前班然后升一年级，大概一年左右的时间，认识了一些同学啥的，
但有个女孩和我关系一直不错，我们因为距离比较近放假经常一起玩，在榆树下够榆树钱，
在村政府的花坛边奔跑，直到我父母回来，把我接回了家，也没有说一声再见。
旧时的记忆已经让我忘记了她的名字，只记得那时候管她叫兰天，这个蓝也不是蓝色的蓝。
转学回家那边上学，听写词语蓝天时，我明明知到蓝天的蓝是蓝，
但还是忍不住写下了“兰天”。
虽然很多年后，再次去到大姑家串门，但也没有了寻找与相见的勇气，只留下些许惆怅。
*/


class Plugin implements PluginInterface
{
    const UPLOAD_DIR  = '/usr/uploads'; //上传文件目录路径
    const PLUGIN_NAME = 'BlueSky'; //插件名称

    public static function activate()
    {
        \Typecho\Plugin::factory('Widget_Upload')->uploadHandle     = __CLASS__.'::uploadHandle';
        \Typecho\Plugin::factory('Widget_Upload')->modifyHandle     = __CLASS__.'::modifyHandle';
        \Typecho\Plugin::factory('Widget_Upload')->deleteHandle     = __CLASS__.'::deleteHandle';
        \Typecho\Plugin::factory('Widget_Upload')->attachmentHandle = __CLASS__.'::attachmentHandle';
    }

    public static function deactivate()
    {

    }

    public static function config(Form $form)
    {

        $api = new Text('api', NULL, 'https://img.lkxin.cn', 'Api：', '只需填写域名包含 http 或 https 无需<code style="padding: 2px 4px; font-size: 90%; color: #c7254e; background-color: #f9f2f4; border-radius: 4px;"> / </code>结尾<br><code style="padding: 2px 4px; font-size: 90%; color: #c7254e; background-color: #f9f2f4; border-radius: 4px;">示例地址：https://img.lkxin.cn/</code>');
        $form->addInput($api);

        $token = new Text('token', NULL, '', 'Token：', '如果为空，则上传的所属用户为游客。<br>如有需要请按示例严格填写：<code style="padding: 2px 4px; font-size: 90%; color: #c7254e; background-color: #f9f2f4; border-radius: 4px;">1|UYsgSjmtTkPjS8qPaLl98dJwdVtU492vQbDFI6pg</code>');
        $form->addInput($token);
        
         $strategy_id = new Text('strategy_id', NULL, '', 'Strategy_id：', '如果为空，则为默认存储id');
        $form->addInput($strategy_id);
        

        echo '<script>window.onload = function(){document.getElementsByName("desc")[0].type = "hidden";}</script>';
    }

    public static function personalConfig(Form $form)
    {
    }

    public static function uploadHandle($file)/*上传文件*/
    {
        if (empty($file['name'])) {

            return false;
        }

        //获取扩展名
        $ext = self::_getSafeName($file['name']);
        //判定是否是允许的文件类型
        if (!Upload::checkFileType($ext) || Common::isAppEngine()) {

            return false;
        }
        // 判断是否是图片
        if (self::_isImage($ext)) {

           return self::_uploadImg($file, $ext);
        }

        return self::_uploadOtherFile($file, $ext);
    }
    
    
    public static function deleteHandle(array $content): bool/*删除文件*/
    {
    
		$ext = $content['attachment']->type;//读取文件扩展名

        if (self::_isImage($ext)) {//判断是否是图片

            return self::_deleteImg($content);
        }

        return unlink($content['attachment']->path);
    }

    public static function modifyHandle($content, $file)
    {
        if (empty($file['name'])) {

            return false;
        }
        $ext = self::_getSafeName($file['name']);
        if ($content['attachment']->type != $ext || Common::isAppEngine()) {

            return false;
        }

        if (!self::_getUploadFile($file)) {

            return false;
        }

        if (self::_isImage($ext)) {//判断是否是图片
            self::_deleteImg($content);

            return self::_uploadImg($file, $ext);
        }
      
        return self::_uploadOtherFile($file, $ext);
    }

    public static function attachmentHandle(array $content): string
    {
		$arr = unserialize($content['text']);
		/*在text字段中截取“.”后的3个字符作为文件扩展名*/
		$ext=$arr['type'];
        if (self::_isImage($ext)) {
            return $arr['path'] ?? '';
        }

        $ret = explode(self::UPLOAD_DIR, $arr['path']);
        return Common::url(self::UPLOAD_DIR . @$ret[1], Options::alloc()->siteUrl);/**/
    }

    private static function _getUploadDir($ext = ''): string /*上传目录*/
    {
        if (self::_isImage($ext)) {
            $url = parse_url(Options::alloc()->siteUrl);
            $DIR = str_replace('.', '_', $url['host']);
            return '/' . $DIR . self::UPLOAD_DIR;
        } elseif (defined('__TYPECHO_UPLOAD_DIR__')) {
            return __TYPECHO_UPLOAD_DIR__;
        } else {
            $path = Common::url(self::UPLOAD_DIR, __TYPECHO_ROOT_DIR__);
            return $path;
        }
    }

    private static function _getUploadFile($file): string
    {
        return $file['tmp_name'] ?? ($file['bytes'] ?? ($file['bits'] ?? ''));
    }

    private static function _getSafeName(&$name): string
    {
        $name = str_replace(array('"', '<', '>'), '', $name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $name = substr($info['basename'], 1);

        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }

    private static function _makeUploadDir($path): bool
    {
        $path    = preg_replace("/\\\+/", '/', $path);
        $current = rtrim($path, '/');
        $last    = $current;

        while (!is_dir($current) && false !== strpos($path, '/')) {
            $last    = $current;
            $current = dirname($current);
        }

        if ($last == $current) {
            return true;
        }

        if (!@mkdir($last)) {
            return false;
        }

        $stat  = @stat($last);
        $perms = $stat['mode'] & 0007777;
        @chmod($last, $perms);

        return self::_makeUploadDir($path);
    }

    private static function _isImage($ext): bool
    {
        $img_ext_arr = array('gif','jpg','jpeg','png','tiff','bmp','ico','psd','webp','JPG','BMP','GIF','PNG','JPEG','ICO','PSD','TIFF','WEBP'); //允许的图片扩展名
        return in_array($ext, $img_ext_arr);
    }

    private static function _uploadOtherFile($file, $ext)
    {
        $dir = self::_getUploadDir($ext) . '/' . date('Y') . '/' . date('m');
        if (!self::_makeUploadDir($dir)) {

            return false;
        }

        $path = sprintf('%s/%u.%s', $dir, crc32(uniqid()), $ext);
        if (!isset($file['tmp_name']) || !@move_uploaded_file($file['tmp_name'], $path)) {

            return false;
        }

        return [
            'name' => $file['name'],
            'path' => $path,
            'size' => $file['size'] ?? filesize($path),
            'type' => $ext,
            'mime' => @Common::mimeContentType($path)
        ];
    }

    private static function _uploadImg($file, $ext)
    {
       
    
        $options = Options::alloc()->plugin(self::PLUGIN_NAME);
        $api     = $options->api . '/api/v1/upload';
		$token   = 'Bearer '.$options->token;
        $strategyId = $options->strategy_id;
        
        $tmp     = self::_getUploadFile($file);
        if (empty($tmp)) {

            return false;
        }

		$img = $file['name'];//保留图片原始名称到图床
        if (!rename($tmp, $img)) {

            return false;
        }
        $params = ['file' => new CURLFile($img)];
        if ($strategyId) {
            $params['strategy_id'] = $strategyId;
        }

        $res = self::_curlPost($api, $params, $token);
          
          
        unlink($img);

        if (!$res) {

            return false;
        }
      

        $json = json_decode($res, true);
     
        
        if ($json['status'] === false) {  // 上传失败
            file_put_contents('./usr/plugins/'.self::PLUGIN_NAME.'/msg.log', json_encode($json, 256) . PHP_EOL, FILE_APPEND);
            return false;
        }
        
        $data = $json['data'];/*图床json信息处理*/
        return [
            'img_key' => $data['key'],//图片唯一key
            'img_id' => $data['md5'],//图片md5
            'name'   => $data['origin_name'],//原始文件名
            'path'   => $data['links']['url'],//图片url
            'size'   => $data['size']*1024,//图片大小
            'type'   => $data['extension'],//图片后缀名
            'mime'   => $data['mimetype'],//图片类型
			'description'  => $data['mimetype'],//图片类型添加到描述
        ];
    }

    private static function _deleteImg(array $content): bool
    {
      
        $options = Options::alloc()->plugin(self::PLUGIN_NAME);
  
        $api     = $options->api . '/api/v1/images';
        $token   = 'Bearer '.$options->token;
     

        $id = $content['attachment']->img_key;
        
        if (empty($id)) {
            return false;
        }
        
        $res  = self::_curlDelete($api . '/' . $id, ['key' => $id], $token);
        $json = json_decode($res, true);
    
        if (!is_array($json)) {

            return false;
        }

        return true;
    }

    private static function _curlDelete($api, $post, $token)
    {
        $headers = array(
            "Content-Type: multipart/form-data",
            "Accept: application/json",
            "Authorization: ".$token,
            );
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/85.0.4183.102 Safari/537.36');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        $res = curl_exec($ch);
        curl_close($ch);

        return $res;
    }
    
    private static function _curlPost($api, $post, $token)
    {
        $headers = array(
            "Content-Type: multipart/form-data",
            "Accept: application/json",
            "Authorization: ".$token,
            );
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/85.0.4183.102 Safari/537.36');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        $res = curl_exec($ch);
        curl_close($ch);

        return $res;
    }
}