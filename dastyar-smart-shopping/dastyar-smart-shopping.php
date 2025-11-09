<?php
/*
Plugin Name: دستیار هوشمند خرید کاربر
Plugin URI: https://example.com
Description: دستیار خرید فارسی با آنبوردینگ مدیریتی، اتصال OpenAI/AvalAI، خواندن داینامیک محصولات ووکامرس، و خوشامدگویی داینامیک.
Version: 2.0.4
Author: Honix Digital Solution
Author URI: mailto:masih.nabizadeh@gmail.com
Text Domain: dastyar-smart-shopping
*/

if (!defined('ABSPATH')) exit;

class DSS_Plugin {
    const OPT_GROUP = 'dss_options_group';
    const OPT_NAME  = 'dss_options';
    const VERSION   = '2.0.4';

    public function __construct(){
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'admin_notices']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_chatbox']);
        add_action('admin_post_dss_build_products', [$this, 'handle_build_products']);
        add_action('admin_post_dss_test_api', [$this, 'handle_test_api']);
        add_action('wp_ajax_dss_chat_reply', [$this, 'ajax_chat_reply']);
        add_action('wp_ajax_nopriv_dss_chat_reply', [$this, 'ajax_chat_reply']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        if (is_admin()) require_once plugin_dir_path(__FILE__) . 'includes/class-product-collector.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-ai-engine.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-conversation.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-onboarding.php';
    }

    public function activate(){
        $defaults = [
            'enabled'           => 'yes',
            'position'          => 'left',
            'api_key'           => '',
            'provider'          => 'openai',
            'max_results'       => 3,
            'onboard_complete'  => 'no',
            'persona'           => 'friendly',
            'greeting_template' => 'سلام! من دستیار خرید {store_name} هستم. آماده‌ام بر اساس {persona_desc} کمک کنم. از بین دسته‌های محبوب: {top_categories} کدوم رو میخوای؟',
            'features'          => ['budget'=>'yes','category'=>'yes','usage'=>'yes']
        ];
        $opts = get_option(self::OPT_NAME, []);
        update_option(self::OPT_NAME, array_merge($defaults, (array)$opts));
    }

    public function admin_menu(){
        add_menu_page('دستیار خرید','دستیار خرید','manage_options','dss-settings',[$this,'settings_page'],'dashicons-format-chat',58);
        add_submenu_page('dss-settings','آنبوردینگ','آنبوردینگ','manage_options','dss-onboarding',[$this,'onboarding_page']);
    }

    public function register_settings(){
        register_setting(self::OPT_GROUP, self::OPT_NAME, ['sanitize_callback'=>[$this,'sanitize_options']]);

        add_settings_section('dss_main','تنظیمات عمومی',function(){
            echo '<p>نمایش فرانت فقط بعد از تکمیل آنبوردینگ و ثبت API فعال می‌شود.</p>';
        },'dss-settings');

        add_settings_field('dss_enabled','فعال‌سازی',function(){
            $o = get_option(self::OPT_NAME, []);
            $val = isset($o['enabled']) ? $o['enabled'] : 'yes';
            echo '<label><input type="checkbox" name="dss_options[enabled]" value="yes" '.( $val==='yes'?'checked':'' ).'> فعال باشد</label>';
        },'dss-settings','dss_main');

        add_settings_field('dss_position','موقعیت باکس',function(){
            $o = get_option(self::OPT_NAME, []);
            $val = isset($o['position']) ? $o['position'] : 'left';
            echo '<select name="dss_options[position]">
                    <option value="left" '.('left'===$val?'selected':'').'>پایینِ چپ</option>
                    <option value="right" '.('right'===$val?'selected':'').'>پایینِ راست</option>
                  </select>';
        },'dss-settings','dss_main');

        add_settings_field('dss_max_results','تعداد نتایج پیشنهادی',function(){
            $o = get_option(self::OPT_NAME, []);
            $val = isset($o['max_results']) ? absint($o['max_results']) : 3;
            echo '<input type="number" min="1" max="50" name="dss_options[max_results]" value="'.esc_attr($val).'" style="width:90px" />';
        },'dss-settings','dss_main');

        add_settings_section('dss_ai','اتصال OpenAI/AvalAI',function(){
            echo '<p>کلید API را وارد کرده و تست اتصال را بزنید. بدون کلید معتبر، چت‌باکس نمایش داده نمی‌شود.</p>';
        },'dss-settings');

        add_settings_field('dss_api_key','کلید API',function(){
            $o = get_option(self::OPT_NAME, []);
            $val = isset($o['api_key']) ? esc_attr($o['api_key']) : '';
            echo '<input type="text" name="dss_options[api_key]" value="'.$val.'" style="width:420px" placeholder="sk-..." autocomplete="off" />';
            $nonce = wp_create_nonce('dss_test_api');
            $url = admin_url('admin-post.php?action=dss_test_api&_wpnonce='.$nonce);
            echo ' <a class="button" href="'.esc_url($url).'">تست اتصال</a>';
        },'dss-settings','dss_ai');

        add_settings_field('dss_provider','ارائه‌دهنده (Provider)',function(){
            $o = get_option(self::OPT_NAME, []);
            $val = isset($o['provider']) ? $o['provider'] : 'openai';
            echo '<select name="dss_options[provider]">';
            echo '<option value="openai" '.( $val==='openai'?'selected':'' ).'>ChatGPT (OpenAI)</option>';
            echo '<option value="avalai" '.( $val==='avalai'?'selected':'' ).'>AvalAI</option>';
            echo '</select>';
        },'dss-settings','dss_ai');

        add_settings_section('dss_persona','شخصیت و پیام خوش‌آمد',function(){
            echo '<p>از {store_name}، {top_categories}، {persona_desc}، {today_date} می‌توانید در متن استفاده کنید.</p>';
        },'dss-settings');

        add_settings_field('dss_persona_select','شخصیت دستیار',function(){
            $o = get_option(self::OPT_NAME, []);
            $val = isset($o['persona']) ? $o['persona'] : 'friendly';
            $opts = ['friendly'=>'دوستانه','luxury'=>'لوکس و رسمی','expert'=>'تخصصی و دقیق','playful'=>'صمیمی و پرانرژی'];
            echo '<select name="dss_options[persona]">';
            foreach ($opts as $k=>$lab){
                echo '<option value="'.esc_attr($k).'" '.($val===$k?'selected':'').'>'.esc_html($lab).'</option>';
            }
            echo '</select>';
        },'dss-settings','dss_persona');

        add_settings_field('dss_greeting_template','قالب پیام خوش‌آمد',function(){
            $o = get_option(self::OPT_NAME, []);
            $val = isset($o['greeting_template']) ? esc_textarea($o['greeting_template']) : '';
            echo '<textarea name="dss_options[greeting_template]" rows="3" style="width:100%">'.$val.'</textarea>';
        },'dss-settings','dss_persona');

        add_settings_section('dss_features','مسیرهای گفتگو',function(){
            echo '<p>مسیرهای فعال برای راهنمایی کاربر را انتخاب کنید.</p>';
        },'dss-settings');

        add_settings_field('dss_features_fields','انتخاب فیلترها',function(){
            $o = get_option(self::OPT_NAME, []);
            $f = isset($o['features']) && is_array($o['features']) ? $o['features'] : [];
            $fields = ['budget'=>'قیمت/بودجه','category'=>'دسته‌بندی','usage'=>'نوع استفاده'];
            foreach ($fields as $k=>$lab){
                $checked = (isset($f[$k]) && $f[$k]==='yes') ? 'checked' : '';
                echo '<label style="margin-right:12px"><input type="checkbox" name="dss_options[features]['.esc_attr($k).']" value="yes" '.$checked.'> '.esc_html($lab).'</label>';
            }
        },'dss-settings','dss_features');
    }

    public function sanitize_options($in){
        $in = is_array($in) ? $in : [];
        $out = [];
        $out['enabled']  = (isset($in['enabled']) && $in['enabled']==='yes') ? 'yes' : 'no';
        $out['position'] = (isset($in['position']) && in_array($in['position'], ['left','right'], true)) ? $in['position'] : 'left';
        $out['api_key']  = isset($in['api_key']) ? sanitize_text_field($in['api_key']) : '';
        $out['provider'] = (isset($in['provider']) && in_array($in['provider'], ['openai','avalai'], true)) ? $in['provider'] : 'openai';
        $out['max_results'] = isset($in['max_results']) ? max(1, min(50, absint($in['max_results']))) : 3;
        $out['onboard_complete'] = (isset($in['onboard_complete']) && $in['onboard_complete']==='yes') ? 'yes' : 'no';
        $allowed_persona = ['friendly','luxury','expert','playful'];
        $out['persona']  = (isset($in['persona']) && in_array($in['persona'], $allowed_persona, true)) ? $in['persona'] : 'friendly';
        // FIX: استفاده از sanitize_textarea_field به جای wp_kses_post برای محافظت از XSS
        $out['greeting_template'] = isset($in['greeting_template']) ? sanitize_textarea_field($in['greeting_template']) : '';
        $out['features'] = [];
        if (!empty($in['features']) && is_array($in['features'])){
            foreach (['budget','category','usage'] as $k){
                $out['features'][$k] = (isset($in['features'][$k]) && $in['features'][$k]==='yes') ? 'yes' : 'no';
            }
        }
        return $out;
    }

    public function onboarding_page(){
        $o = get_option(self::OPT_NAME, []);
        echo '<div class="wrap"><h1>آنبوردینگ دستیار خرید</h1>';
        echo '<ol style="line-height:1.9">';
        echo '<li>کلید API را در <a href="'.esc_url(admin_url('admin.php?page=dss-settings#dss_ai')).'">تنظیمات</a> ذخیره و «تست اتصال» را بزنید.</li>';
        echo '<li>شخصیت دستیار و پیام خوش‌آمد را در تنظیمات تعیین کنید.</li>';
        echo '<li>مسیرهای گفتگو (قیمت/دسته/کاربرد) را انتخاب کنید.</li>';
        echo '</ol>';
        echo '<form method="post" action="'.esc_url(admin_url('options.php')).'">';
        settings_fields(self::OPT_GROUP);
        $checked = (isset($o['onboard_complete']) && $o['onboard_complete']==='yes') ? 'checked' : '';
        echo '<h2>اتمام آنبوردینگ</h2>';
        echo '<label><input type="checkbox" name="dss_options[onboard_complete]" value="yes" '.$checked.'> آنبوردینگ تکمیل شد</label>';
        submit_button('ذخیره');
        echo '</form></div>';
    }

    public function settings_page(){
        echo '<div class="wrap"><h1>دستیار هوشمند خرید کاربر</h1>';
        echo '<form method="post" action="'.esc_url(admin_url('options.php')).'">';
        settings_fields(self::OPT_GROUP);
        do_settings_sections('dss-settings');
        submit_button();
        echo '</form></div>';
    }

    public function admin_notices(){
        if (!current_user_can('manage_options')) return;
        $o = get_option(self::OPT_NAME, []);
        $api = isset($o['api_key']) ? trim($o['api_key']) : '';
        $done = isset($o['onboard_complete']) && $o['onboard_complete']==='yes';
        if (empty($api) || !$done){
            echo '<div class="notice notice-warning"><p>دستیار خرید هنوز فعال نشده: لطفاً آنبوردینگ را تکمیل و کلید API را ثبت کنید.</p></div>';
        }
    }

    public function enqueue_assets(){
        $o = get_option(self::OPT_NAME, []);
        $enabled = isset($o['enabled']) && $o['enabled']==='yes';
        $api = isset($o['api_key']) ? trim($o['api_key']) : '';
        $done = isset($o['onboard_complete']) && $o['onboard_complete']==='yes';
        if (!($enabled && $api && $done)) return;
        wp_enqueue_style('dss-chatbox', plugins_url('assets/css/chatbox.css', __FILE__), [], self::VERSION);
        wp_enqueue_script('dss-chatbox', plugins_url('assets/js/chatbox.js', __FILE__), ['jquery'], self::VERSION, true);
        $greeting = DSS_Onboarding::compile_greeting();
        $nonce = wp_create_nonce('dss_chat_nonce');
        wp_localize_script('dss-chatbox', 'DSS_CONFIG', [
            'greeting' => $greeting,
            'position' => isset($o['position']) ? $o['position'] : 'left',
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => $nonce
        ]);
    }

    public function render_chatbox(){
        $o = get_option(self::OPT_NAME, []);
        $enabled = isset($o['enabled']) && $o['enabled']==='yes';
        $api = isset($o['api_key']) ? trim($o['api_key']) : '';
        $done = isset($o['onboard_complete']) && $o['onboard_complete']==='yes';
        if (!($enabled && $api && $done)) return;
        $position = isset($o['position']) ? $o['position'] : 'left';
        // FIX: Proper escaping for HTML output
        ?>
        <div id="dss-launcher" class="dss-launcher dss-pos-<?php echo esc_attr($position); ?>" aria-label="chat launcher">?</div>
        <div id="dss-chat" class="dss-chat dss-pos-<?php echo esc_attr($position); ?>" dir="rtl" aria-live="polite">
            <div class="dss-header">
                <div class="dss-title">دستیار هوشمند خرید</div>
                <button class="dss-close" aria-label="بستن">×</button>
            </div>
            <div class="dss-body">
                <div class="dss-msg dss-bot"></div>
            </div>
            <div class="dss-input">
                <input type="text" placeholder="سؤال یا نیازت را بنویس..."/>
                <button class="dss-send">ارسال</button>
            </div>
        </div>
        <?php
    }

    public function handle_build_products(){
        wp_die('این قابلیت در نسخه 2.0.0 حذف شده است.');
    }

    public function handle_test_api(){
        if ( ! current_user_can('manage_options') ) wp_die('Unauthorized');
        check_admin_referer('dss_test_api');
        $o = get_option(self::OPT_NAME, []);
        $api = isset($o['api_key']) ? trim($o['api_key']) : '';
        if (!$api){
            wp_safe_redirect(add_query_arg(['dss_api'=>'0'], admin_url('admin.php?page=dss-settings')));
            exit;
        }
        $endpoint = (isset($o['provider']) && $o['provider']==='avalai') ? 'https://api.avalai.ir/v1/chat/completions' : 'https://api.openai.com/v1/chat/completions';
        $args = [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api,
            ],
            // FIX: استفاده از مدل جدیدتر
            'body'    => wp_json_encode([
                'model'=>'gpt-4o-mini',
                'messages'=>[['role'=>'user','content'=>'ping']],
                'max_tokens'=>2
            ]),
            'timeout' => 15
        ];
        $res = wp_remote_post($endpoint, $args);
        $ok = !is_wp_error($res) && wp_remote_retrieve_response_code($res)===200;
        wp_safe_redirect(add_query_arg(['dss_api'=>$ok?'1':'0'], admin_url('admin.php?page=dss-settings')));
        exit;
    }

    protected function ratelimit_ok($sid){
        $sid = preg_replace('/[^a-zA-Z0-9_\-]/','',$sid);
        $last_key = 'dss_rl_last_' . $sid;
        $cnt_key  = 'dss_rl_cnt_' . date('YmdH') . '_' . $sid;
        $last = get_transient($last_key);
        if ($last && (time() - intval($last) < 2)) return false;
        $cnt = intval(get_transient($cnt_key));
        if ($cnt > 60) return false;
        set_transient($last_key, time(), 120);
        set_transient($cnt_key, $cnt+1, 2*HOUR_IN_SECONDS);
        return true;
    }

    public function ajax_chat_reply(){
        check_ajax_referer('dss_chat_nonce','nonce');
        $message = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : '';
        $sid = isset($_POST['sid']) ? sanitize_text_field($_POST['sid']) : '';
        if (!$message){
            wp_send_json_error(['error'=>'پیامی دریافت نشد']);
        }
        if (!$sid){
            $sid = 'dss_' . wp_generate_uuid4();
        }
        if ( ! $this->ratelimit_ok($sid) ){
            wp_send_json_error(['error'=>'لطفاً کمی مکث کن، درخواست‌های پیاپی زیادی ارسال شد.']);
        }
        $reply = DSS_Conversation::handle($sid, $message);
        // FIX: بهتر checking برای empty response
        if (is_array($reply) && isset($reply['ok']) && $reply['ok']){
            wp_send_json_success([
                'text'=> isset($reply['text']) ? (string)$reply['text'] : '',
                'sid'=>$sid,
                'cards'=> isset($reply['cards']) && is_array($reply['cards']) ? $reply['cards'] : [],
                'quick'=> isset($reply['quick']) && is_array($reply['quick']) ? $reply['quick'] : []
            ]);
        } else {
            wp_send_json_error(['error'=> (is_array($reply) && isset($reply['error'])) ? $reply['error'] : 'خطا در پردازش پیام']);
        }
    }
}
new DSS_Plugin();