<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * COS Service-Based Upload Plugin
 *
 * @package CosUpload
 * @version 0.1.0
 * @author Manic Rabbit
 * @link https://manicrabbit.com
 */

class CosUpload_Plugin implements Typecho_Plugin_Interface
{
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Upload')->uploadHandle = array('CosUpload_Plugin', 'uploadHandle');
        Typecho_Plugin::factory('Widget_Upload')->attachmentHandle = array('CosUpload_Plugin', 'attachmentHandle');
        Typecho_Plugin::factory('Widget_Upload')->deleteHandle = array('CosUpload_Plugin', 'deleteHandle');
        Typecho_Plugin::factory('Widget_Upload')->modifyHandle = array('CosUpload_Plugin', 'modifyHandle');

        return _t("配置腾讯云存储对象后使用");
    }

    public static function deactivate() {}

    /**
     * 插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        /** 分类名称 */
        $secretId = new Typecho_Widget_Helper_Form_Element_Text('secretId', NULL, '', _t('Secret Id'));
        $secretKey = new Typecho_Widget_Helper_Form_Element_Text('secretKey', NULL, '', _t('Secret Key'),
            sprintf("SecretId 和 SecretKey 可在控制台 <a target='_blank' href='%s'>云API密钥页面</a> 获取", "https://console.cloud.tencent.com/capi"));
        $region = new Typecho_Widget_Helper_Form_Element_Text('region', NULL, '', _t('区域'));
        $schema = new Typecho_Widget_Helper_Form_Element_Radio('schema', array('https' => 'HTTPS', 'http' => 'HTTP'), 'https', _t('API请求协议'), _t('设置SDK请求时使用协议，默认为 HTTP'));
        $bucket = new Typecho_Widget_Helper_Form_Element_Text('bucket', NULL, '', _t('存储桶名称'));
        $domain = new Typecho_Widget_Helper_Form_Element_Text('domain', NULL, '', _t('CDN或存储桶域名'));

        $form->addInput($secretId->addRule('required', _t('Secret ID 不能为空')));
        $form->addInput($secretKey->addRule('required', _t('Secret KEY 不能为空')));
        $form->addInput($region->addRule('required', _t('区域 不能为空')));
        $form->addInput($schema);
        $form->addInput($bucket->addRule('required', _t('存储桶名称 不能为空')));
        $form->addInput($domain->addRule('required', _t('CDN 或 存储桶访问域名不能为空')));
    }
    public static function personalConfig(Typecho_Widget_Helper_Form $form){}

    public static function getClientOptions()
    {
        $options = Typecho_Widget::widget('Widget_Options')->plugin('Upload');

        return [
            'region' => $options->region,
            'schema' => $options->schema,
            'credentials' => [
                'secretId' => $options->secretId,
                'secretKey' => $options->secretKey,
            ]
        ];
    }

    public static function uploadHandle($file)
    {

        $bucket = Typecho_Widget::widget('Widget_Options')->plugin('Upload')->bucket;
        if ( ! $bucket ) {
            return false;
        }

        $ext = self::getSafeName($file['name']);
        if ( ! Widget_Upload::checkFileType($ext) ) {
            return false;
        }

        $fileName = sprintf("%s", date('Y/m')) . '/'. sprintf('%u', crc32(uniqid())) . '.' . $ext;
        $path = $file['tmp_name'];
        $size = isset($file['size']) ? $file['size'] : filesize($path);

        $client = self::client();

        try {
            $result = $client->Upload(
                $bucket = $bucket,
                $key = $fileName,
                $body = fopen($path, 'rb')
            );

            //返回相对存储路径
            return array(
                'name' => basename($fileName),
                'path' => $fileName,
                'size' => $size,
                'type' => $ext,
                'mime' => Typecho_Common::mimeContentType($path)
            );

        } catch (Exception $except) {
            return false;
        }
    }

    public static function attachmentHandle($content)
    {
        $domain = Typecho_Widget::widget('Widget_Options')->plugin('Upload')->domain;
        return Typecho_Common::url($content['attachment']->path, (sprintf("http%s://", Typecho_Request::isSecure() ? 's' : '')) . ltrim($domain, '/'));
    }

    public static function client()
    {
        require dirname(__file__) . "/vendor/autoload.php";
        $options = self::getClientOptions();
        return new Qcloud\Cos\Client($options);
    }

    public static function deleteHandle(array $content)
    {
        $client = self::client();
        $bucket = Typecho_Widget::widget('Widget_Options')->plugin('Upload')->bucket;

        try {

            $client->deleteObject([
                'Bucket' => $bucket,
                'Key' => $content['attachment']->path
            ]);

        } catch (Exception $e) {
            return false;
        }
    }

    public static function modifyHandle($content, $file)
    {
        return self::uploadHandle($file);
    }

    private static function getSafeName($name)
    {
        $name = str_replace(array('"', '<', '>'), '', $name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $name = substr($info['basename'], 1);

        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }
}