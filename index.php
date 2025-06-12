 		// 设置代理，格式  ip:port
        $proxyServer = "ip:port";

        $ch = curl_init();
        // 设置请求地址
        curl_setopt($ch, CURLOPT_URL, "http://myip.ipip.net");
        // 设置代理，格式  http://ip:port
        curl_setopt($ch, CURLOPT_PROXY, "http://$proxyServer");
        // https请求 不验证证书和hosts
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		//账号密码验证
        //curl_setopt($ch, CURLOPT_PROXYUSERPWD, "账号:密码");

        $output = curl_exec($ch);
        if ($output === FALSE) {
            echo "CURL Error:" . curl_error($ch);
        } else {
            echo $output;
        }
        curl_close($ch);