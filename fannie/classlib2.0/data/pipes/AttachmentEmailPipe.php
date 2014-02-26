<?php
/*******************************************************************************

    Copyright 2013 Whole Foods Co-op

    This file is part of Fannie.

    IT CORE is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    IT CORE is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    in the file license.txt along with IT CORE; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*********************************************************************************/

if (!class_exists('NewMemberEmailPipe')) {
    include_once(dirname(__FILE__).'/NewMemberEmailPipe.php');
}
/**
*/
class AttachmentEmailPipe extends NewMemberEmailPipe
{
    public function processMail($msg)
    {
        $info = $this->parseEmail($msg);
        
        $boundary = $this->hasAttachments($info['headers']);

        if ($boundary) {
            $pieces = $this->extractAttachments($info['body'], $boundary);
            echo $pieces['body'];
            echo "Attachments: " . count($pieces['attachments']) . "\n";
            foreach($pieces['attachments'] as $a) {
                echo "File: {$a['name']}\n";
                $fp = fopen('./' . $a['name'], 'w');
                fwrite($fp, $a['content']);
                fclose($fp);
            }
        }
    }

    protected function hasAttachments($headers)
    {
        if (isset($headers['Content-Type'])) {
            $test = preg_match('/\s*multipart\/mixed;\s*boundary="(.*)"/', $headers['Content-Type'], $matches);
            if ($test == 1) {
                return $matches[1];
            }
        }

        return false;
    }

    protected function extractAttachments($body, $boundary)
    {
        $parts = explode("--{$boundary}", $body);
        $attachments = array();
        $actual_body = '';
        foreach($parts as $part) {
            $part = ltrim($part, "\r\n");
            if (empty($part)) continue;
            $info = $this->parseEmail($part);
            if (count($info['headers']) == 0) continue;

            if (!isset($info['headers']['Content-Type'])) continue;

            $mime = preg_match('/(.+\/.+);\s*/', $info['headers']['Content-Type'], $matches);
            if ($mime != 1) continue;
            $mime = $matches[1];

            if (substr($mime, 0, 4) == 'text') {
                $actual_body .= $info['body'];
            } else {
                $attachment = trim($info['body']);

                $fn = time();
                if (isset($info['headers']['Content-Disposition'])) {
                    if (preg_match('/filename="(.+)"/', $info['headers']['Content-Disposition'], $matches)) {
                        $fn = $matches[1]; 
                    }
                }

                if (!isset($info['headers']['Content-Transfer-Encoding'])) {
                    $info['headers']['Content-Transfer-Encoding'] = 'none';
                }
                $decoded = '';
                switch($info['headers']['Content-Transfer-Encoding']) {
                    case 'base64':
                        $decoded = base64_decode($attachment);
                        break;
                    default:
                        $decoded = $attachment;
                        break;
                }
                
                $attachments[] = array(
                    'name' => $fn,
                    'type' => $mime,
                    'content' => $decoded, 
                );
            }

        }
        return array('body'=>$actual_body, 'attachments'=>$attachments);
    }

}

if (php_sapi_name() === 'cli' && basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    $obj = new AttachmentEmailPipe();
    $message = file_get_contents("php://stdin");
    if (!empty($message)) {
        $obj->processMail($message);
    }
} 
