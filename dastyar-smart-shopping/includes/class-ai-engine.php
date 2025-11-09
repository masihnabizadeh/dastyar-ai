<?php
if (!defined('ABSPATH')) exit;

class DSS_AI_Engine {

    protected static function extract_keywords($message){
        $m = mb_strtolower($message);
        $m = preg_replace('/[\s\,\.;:!؟?]+/u',' ', $m);
        $parts = array_filter(array_unique(explode(' ', $m)));
        $stop = ['و','یا','برای','میخوام','می‌خوام','زیر','بالا','بین','از','تا','این','که','چه','چی','هست','رو','به','در','با','the','a','an','and','or','of','to'];
        $keywords = array_values(array_diff($parts, $stop));
        return array_slice($keywords, 0, 8);
    }

    protected static function fetch_products_dynamic($keywords, $limit = 200){
        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'fields' => 'ids',
            'no_found_rows' => true,
        ];
        if (!empty($keywords)){
            $args['s'] = implode(' ', $keywords);
        }
        $q = new WP_Query($args);
        $ids = $q->posts;

        $out = [];
        foreach ($ids as $pid){
            $p = wc_get_product($pid);
            if (!$p) continue;
            
            $out[] = [
                'product_id' => $pid,
                'title' => $p->get_name(),
                'short_description' => wp_strip_all_tags($p->get_short_description()),
                'full_description'  => wp_strip_all_tags($p->get_description()),
                'price' => $p->get_price(),
                'url'   => get_permalink($pid),
                'image' => wp_get_attachment_image_url($p->get_image_id(), 'medium'),
                'categories' => wp_get_post_terms($pid, 'product_cat', ['fields'=>'names']),
                'tags'       => wp_get_post_terms($pid, 'product_tag', ['fields'=>'names']),
                'attributes' => self::get_attributes($p, $pid),
                'meta_data'  => self::get_meta($pid),
            ];
        }
        return $out;
    }

    protected static function get_attributes($p, $pid){
        $attrs = [];
        foreach ($p->get_attributes() as $key => $attr){
            if ( is_a($attr, 'WC_Product_Attribute') ) {
                $label = wc_attribute_label( $attr->get_name() );
                if ( $attr->is_taxonomy() ) {
                    $terms = wc_get_product_terms( $pid, $attr->get_name(), ['fields'=>'names'] );
                    $attrs[$label] = $terms;
                } else {
                    $attrs[$label] = $attr->get_options();
                }
            }
        }
        return $attrs;
    }

    protected static function get_meta($pid){
        $meta = [];
        $raw = get_post_meta($pid);
        if (is_array($raw)){
            foreach ($raw as $k=>$vals){
                if (strpos($k, '_')===0) continue;
                $meta[$k] = is_array($vals) && count($vals)===1 ? $vals[0] : $vals;
            }
        }
        return $meta;
    }

    protected static function score_product($prod, $keywords){
        $text_parts = [];
        foreach (['title','short_description','full_description'] as $k){
            if (!empty($prod[$k])) $text_parts[] = mb_strtolower($prod[$k]);
        }
        if (!empty($prod['categories'])) $text_parts[] = mb_strtolower(implode(' ', (array)$prod['categories']));
        if (!empty($prod['tags'])) $text_parts[] = mb_strtolower(implode(' ', (array)$prod['tags']));
        if (!empty($prod['attributes']) && is_array($prod['attributes'])){
            foreach ($prod['attributes'] as $k=>$v){
                $text_parts[] = mb_strtolower($k.' '.(is_array($v)? implode(' ', $v): $v));
            }
        }
        if (!empty($prod['meta_data']) && is_array($prod['meta_data'])){
            foreach ($prod['meta_data'] as $k=>$v){
                $text_parts[] = mb_strtolower($k.' '.(is_array($v)? implode(' ', $v): $v));
            }
        }
        $hay = implode(' ', $text_parts);
        $score = 0;
        foreach ($keywords as $kw){
            if (!$kw) continue;
            $kw = trim(mb_strtolower($kw));
            if ($kw && mb_strpos($hay, $kw) !== false){ $score += 1; }
        }
        return $score;
    }

    public static function suggest_structured($user_message){
        $opts = get_option('dss_options', []);
        $api_key = isset($opts['api_key']) ? trim($opts['api_key']) : '';
        $provider = isset($opts['provider']) ? $opts['provider'] : 'openai';
        $max_results = isset($opts['max_results']) ? intval($opts['max_results']) : 3;

        $keywords = self::extract_keywords($user_message);
        $products = self::fetch_products_dynamic($keywords, 200);

        if (empty($products)){
            $products = self::fetch_products_dynamic([], 50);
        }
        if (empty($products)){
            return ['ok'=>true,'text'=>'فعلاً محصولی پیدا نشد. لطفاً دسته یا بودجه را دقیق‌تر بگو.','cards'=>[]];
        }

        $scored = [];
        foreach ($products as $p){
            $sc = self::score_product($p, $keywords);
            $scored[] = ['score'=>$sc,'p'=>$p];
        }
        usort($scored, function($a,$b){ return $b['score'] <=> $a['score']; });
        $top = array_slice($scored, 0, max(1, $max_results));

        $cards = [];
        foreach ($top as $row){
            $p = $row['p'];
            $cards[] = [
                'title' => isset($p['title']) ? $p['title'] : '',
                'price' => isset($p['price']) ? $p['price'] : '',
                'url'   => isset($p['url']) ? $p['url'] : '#',
                'image' => isset($p['image']) ? $p['image'] : ''
            ];
        }

        $text = 'این‌ها نزدیک‌ترین پیشنهادها به نیازت هستند:';
        
        if ($api_key){
            $body = [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role'=>'system','content'=>'تو یک دستیار خرید فارسی هستی. یک جمله کوتاه برای معرفی پیشنهادات بنویس.'],
                    ['role'=>'user','content'=>$user_message]
                ],
                'temperature' => 0.2,
                'max_tokens' => 50
            ];
            $args = [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ],
                'body'    => wp_json_encode($body),
                'timeout' => 15
            ];
            
            $endpoint = ($provider === 'avalai') 
                ? 'https://api.avalai.ir/v1/chat/completions' 
                : 'https://api.openai.com/v1/chat/completions';
            
            $response = wp_remote_post($endpoint, $args);
            
            if (!is_wp_error($response)){
                $code = wp_remote_retrieve_response_code($response);
                $raw  = wp_remote_retrieve_body($response);
                if ($code === 200){
                    $data = json_decode($raw, true);
                    if (isset($data['choices'][0]['message']['content'])){
                        $txt = trim($data['choices'][0]['message']['content']);
                        if ($txt) $text = $txt;
                    }
                }
            }
        }

        return ['ok'=>true,'text'=>$text,'cards'=>$cards];
    }
}
