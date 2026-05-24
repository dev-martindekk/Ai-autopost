<?php
/**
 * AI AutoPost SEO System - Internal Link Builder
 * ===============================================
 * ใช้ AI วิเคราะห์และสร้าง Internal Links อย่างเป็นธรรมชาติ
 * - วิเคราะห์บทความเก่าที่เผยแพร่แล้ว
 * - เลือก anchor text ที่เหมาะสม (ไม่ใช่แค่ exact match)
 * - จัดวาง link อย่างเป็นธรรมชาติในเนื้อหา
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai_orchestrator.php';

class InternalLinkBuilder {

    private $ai;

    public function __construct() {
        $this->ai = aiOrchestrator();
    }

    /**
     * สร้าง Internal Links สำหรับบทความใหม่
     * =============================================
     * กฎการทำลิงก์:
     * 1. Homepage Link - ต้องมีเสมอ 1 ลิงก์
     * 2. Internal Links - ไม่เกิน 1 ลิงก์
     * 3. Outbound Link - 1 ลิงก์
     *
     * ⚠️ กฎสำคัญ: 1 paragraph = 1 link เท่านั้น (กระจายทั่วบทความ)
     *
     * @param string $content เนื้อหาบทความ
     * @param int $siteId Site ID
     * @param string $primaryKeyword Primary keyword ของบทความใหม่
     * @return array [content => modified content, links_added => count, log => details]
     */
    public function buildInternalLinks(string $content, int $siteId, string $primaryKeyword): array {
        $logs = [];
        $internalLinksAdded = 0;
        $maxInternalLinks = 1;

        // 1. ดึงข้อมูลเว็บไซต์
        $site = db()->fetchOne("SELECT * FROM sites WHERE id = ?", [$siteId]);
        if (!$site) {
            return ['content' => $content, 'links_added' => 0, 'logs' => ['Site not found']];
        }
        $homepageUrl = rtrim($site['base_url'] ?? '', '/');
        $homepageKeyword = $this->getNextHomepageKeyword($site);

        // 2. ดึงบทความที่เผยแพร่แล้ว
        $publishedArticles = $this->getPublishedArticles($siteId);

        // 3. แปลง plain text เป็น HTML paragraphs (ถ้าจำเป็น)
        $content = $this->ensureHtmlParagraphs($content);

        // 4. วิเคราะห์ paragraphs ทั้งหมดในบทความ
        $paragraphs = $this->analyzeParagraphs($content);
        $totalParagraphs = count($paragraphs);
        $logs[] = "📄 พบ {$totalParagraphs} paragraphs ในบทความ";

        // 4. คำนวณตำแหน่งสำหรับใส่ลิงก์แต่ละประเภท (กระจายทั่วบทความ)
        // รวมลิงก์ทั้งหมด: 1 homepage + 1 internal + 1 outbound = 3 ลิงก์
        $totalLinks = 1 + min($maxInternalLinks, count($publishedArticles)) + 1;
        $linkPositions = $this->calculateLinkPositions($totalParagraphs, $totalLinks);
        $logs[] = "🎯 จัดตำแหน่งลิงก์: " . implode(', ', array_map(fn($p) => "P{$p}", $linkPositions));

        $positionIndex = 0;
        $usedParagraphs = []; // เก็บ paragraph ที่ใส่ลิงก์แล้ว

        // =============================================
        // STEP 1: Homepage Link (ตำแหน่งแรก)
        // =============================================
        if ($homepageUrl && $homepageKeyword) {
            $targetPos = $linkPositions[$positionIndex] ?? 2;
            $result = $this->insertLinkAtParagraph($content, $homepageKeyword, $homepageUrl, $homepageKeyword, $targetPos, $usedParagraphs);
            $content = $result['content'];
            $usedParagraphs[] = $result['paragraph'];
            $positionIndex++;
            $logs[] = "✅ Homepage Link (P{$result['paragraph']}): \"{$homepageKeyword}\" → {$homepageUrl}" .
                      ($result['injected'] ? ' (แทรก)' : '');
        } else {
            $logs[] = "⚠️ ไม่มี homepage_keyword";
        }

        // =============================================
        // STEP 2: Internal Links (กระจายตามตำแหน่ง)
        // =============================================
        if (!empty($publishedArticles)) {
            foreach ($publishedArticles as $article) {
                if ($internalLinksAdded >= $maxInternalLinks) break;

                $articleKeyword = $article['primary_keyword'] ?? '';
                $articleUrl = $article['post_url'] ?? '';
                $articleTitle = $article['title'] ?? '';

                if (empty($articleKeyword) || empty($articleUrl)) continue;

                $targetPos = $linkPositions[$positionIndex] ?? ($positionIndex * 2 + 3);
                $result = $this->insertLinkAtParagraph($content, $articleKeyword, $articleUrl, $articleTitle, $targetPos, $usedParagraphs);
                $content = $result['content'];
                $usedParagraphs[] = $result['paragraph'];
                $positionIndex++;
                $internalLinksAdded++;

                $logs[] = "✅ Internal #{$internalLinksAdded} (P{$result['paragraph']}): \"{$articleKeyword}\"" .
                          ($result['injected'] ? ' (แทรก)' : '');
            }

            $logs[] = "📊 Internal Links: {$internalLinksAdded}/{$maxInternalLinks}";
        } else {
            $logs[] = "ℹ️ ไม่พบบทความเก่า";
        }

        // =============================================
        // STEP 3: Outbound Link (ตำแหน่งสุดท้าย)
        // =============================================
        $outboundResult = $this->addOutboundLinkAtPosition($content, $siteId, $linkPositions[$positionIndex] ?? $totalParagraphs - 1, $usedParagraphs);
        if ($outboundResult['added']) {
            $content = $outboundResult['content'];
            $logs[] = "✅ Outbound (P{$outboundResult['paragraph']}): \"{$outboundResult['anchor']}\"" .
                      ($outboundResult['injected'] ? ' (แทรก)' : '');
        } else {
            $logs[] = "ℹ️ ไม่มี Outbound Link";
        }

        return [
            'content' => $content,
            'links_added' => $internalLinksAdded,
            'homepage_link' => $homepageKeyword ? true : false,
            'outbound_link' => $outboundResult['added'] ? $outboundResult : null,
            'logs' => $logs
        ];
    }

    /**
     * แปลง plain text เป็น HTML paragraphs (ถ้าเนื้อหายังไม่มี <p> tags)
     */
    private function ensureHtmlParagraphs(string $content): string {
        // ถ้ามี <p> tags อยู่แล้ว ไม่ต้องแปลง
        if (preg_match('/<p[^>]*>/i', $content)) {
            return $content;
        }

        // แยกส่วน headings ออกมาก่อน
        $parts = preg_split('/(<h[1-6][^>]*>.*?<\/h[1-6]>)/is', $content, -1, PREG_SPLIT_DELIM_CAPTURE);

        $result = '';
        foreach ($parts as $part) {
            // ถ้าเป็น heading ให้เก็บไว้เหมือนเดิม
            if (preg_match('/<h[1-6][^>]*>/i', $part)) {
                $result .= $part;
                continue;
            }

            // แปลงเนื้อหาเป็น paragraphs
            // แยกตาม double newline หรือ <br><br>
            $paragraphs = preg_split('/\n\s*\n|<br\s*\/?>\s*<br\s*\/?>/', $part, -1, PREG_SPLIT_NO_EMPTY);

            foreach ($paragraphs as $para) {
                $para = trim($para);
                if (empty($para)) continue;

                // ถ้าไม่ใช่ heading และยังไม่มี <p> ให้ wrap
                if (!preg_match('/^<[hH][1-6]/', $para) && !preg_match('/^<p/i', $para)) {
                    // แปลง single newline เป็น <br>
                    $para = preg_replace('/\n/', '<br>', $para);
                    $result .= "<p>{$para}</p>\n";
                } else {
                    $result .= $para . "\n";
                }
            }
        }

        return trim($result);
    }

    /**
     * วิเคราะห์ paragraphs ในบทความ
     * หมายเหตุ: ใช้ strlen (byte length) ไม่ใช่ mb_strlen เพราะ preg_match returns byte offsets
     */
    private function analyzeParagraphs(string $content): array {
        $paragraphs = [];
        preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $content, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $index => $match) {
            $paragraphs[] = [
                'index' => $index,
                'start' => $match[1],
                'end' => $match[1] + strlen($match[0]), // ใช้ strlen ไม่ใช่ mb_strlen
                'content' => $match[0],
                'has_link' => (strpos($match[0], '<a ') !== false)
            ];
        }

        return $paragraphs;
    }

    /**
     * คำนวณตำแหน่งที่เหมาะสมสำหรับใส่ลิงก์ (กระจายทั่วบทความ)
     */
    private function calculateLinkPositions(int $totalParagraphs, int $totalLinks): array {
        if ($totalParagraphs <= $totalLinks) {
            return range(1, $totalParagraphs);
        }

        $positions = [];
        $interval = floor($totalParagraphs / ($totalLinks + 1));

        for ($i = 1; $i <= $totalLinks; $i++) {
            $pos = $i * $interval;
            // ไม่ใส่ paragraph แรก (มักเป็น intro) และ paragraph สุดท้าย (มักเป็น conclusion)
            $pos = max(2, min($pos, $totalParagraphs - 1));
            $positions[] = $pos;
        }

        return array_unique($positions);
    }

    /**
     * ใส่ลิงก์ใน paragraph ที่กำหนด (1 link per paragraph)
     */
    private function insertLinkAtParagraph(string $content, string $keyword, string $url, string $title, int $targetParagraph, array $usedParagraphs): array {
        // ตรวจสอบว่า URL นี้มีในเนื้อหาแล้วหรือไม่
        if (mb_stripos($content, $url) !== false) {
            return ['content' => $content, 'paragraph' => 0, 'injected' => false, 'skipped' => true];
        }

        // หา paragraph ที่เหมาะสม (ไม่ซ้ำกับที่ใช้แล้ว)
        $paragraphs = $this->analyzeParagraphs($content);
        $actualParagraph = $this->findAvailableParagraph($paragraphs, $targetParagraph, $usedParagraphs);

        if ($actualParagraph === null) {
            // ไม่มี paragraph ว่าง - แทรก paragraph ใหม่
            return $this->injectNewParagraphWithLink($content, $keyword, $url, $title, $targetParagraph);
        }

        // ลองหา keyword ใน paragraph นั้น
        $para = $paragraphs[$actualParagraph];
        if (mb_stripos($para['content'], $keyword) !== false && !$para['has_link']) {
            // พบ keyword และยังไม่มี link - ใส่ link
            $result = $this->insertLinkInParagraph($content, $keyword, $url, $para);
            if ($result['success']) {
                return ['content' => $result['content'], 'paragraph' => $actualParagraph + 1, 'injected' => false];
            }
        }

        // ไม่เจอ keyword หรือใส่ไม่ได้ - แทรกข้อความใหม่ต่อท้าย paragraph นั้น
        return $this->appendLinkToParagraph($content, $keyword, $url, $title, $para, $actualParagraph);
    }

    /**
     * หา paragraph ที่ยังว่างอยู่ (ไม่มี link และยังไม่ได้ใช้)
     */
    private function findAvailableParagraph(array $paragraphs, int $target, array $usedParagraphs): ?int {
        $totalParagraphs = count($paragraphs);
        if ($totalParagraphs === 0) return null;

        // ลอง target ก่อน
        $target = min($target, $totalParagraphs) - 1; // convert to 0-based index
        if ($target >= 0 && !in_array($target + 1, $usedParagraphs) && !$paragraphs[$target]['has_link']) {
            return $target;
        }

        // หา paragraph ใกล้เคียงที่ว่าง
        for ($offset = 1; $offset < $totalParagraphs; $offset++) {
            // ลองด้านหลัง
            $tryIndex = $target + $offset;
            if ($tryIndex < $totalParagraphs && $tryIndex >= 0) {
                if (!in_array($tryIndex + 1, $usedParagraphs) && !$paragraphs[$tryIndex]['has_link']) {
                    return $tryIndex;
                }
            }

            // ลองด้านหน้า
            $tryIndex = $target - $offset;
            if ($tryIndex >= 0 && $tryIndex < $totalParagraphs) {
                if (!in_array($tryIndex + 1, $usedParagraphs) && !$paragraphs[$tryIndex]['has_link']) {
                    return $tryIndex;
                }
            }
        }

        return null;
    }

    /**
     * ใส่ link ใน paragraph ที่มี keyword อยู่แล้ว
     */
    private function insertLinkInParagraph(string $content, string $keyword, string $url, array $para): array {
        $paraContent = $para['content'];

        // หา keyword ใน paragraph
        $pos = mb_stripos($paraContent, $keyword);
        if ($pos === false) {
            return ['success' => false, 'content' => $content];
        }

        // สร้าง link
        $actualKeyword = mb_substr($paraContent, $pos, mb_strlen($keyword));
        $linkHtml = '<a href="' . htmlspecialchars($url) . '"><strong>' . $actualKeyword . '</strong></a>';

        // แทนที่ keyword ด้วย link
        $newParaContent = mb_substr($paraContent, 0, $pos) . $linkHtml . mb_substr($paraContent, $pos + mb_strlen($keyword));

        // แทนที่ paragraph ในเนื้อหา (ใช้ substr เพราะ $para['start']/$para['end'] เป็น byte offset)
        $newContent = substr($content, 0, $para['start']) . $newParaContent . substr($content, $para['end']);

        return ['success' => true, 'content' => $newContent];
    }

    /**
     * แทรก keyword พร้อม link เข้าไปในเนื้อหา paragraph อย่างเป็นธรรมชาติ
     * แทรกหลังจุดจบประโยคกลาง paragraph (ไม่ใช่ท้ายสุด)
     */
    private function appendLinkToParagraph(string $content, string $keyword, string $url, string $title, array $para, int $paraIndex): array {
        $linkHtml = '<a href="' . htmlspecialchars($url) . '"><strong>' . htmlspecialchars($keyword) . '</strong></a>';

        $paraText = $para['content'];

        // ดึงเนื้อหา paragraph (ไม่รวม <p> และ </p>)
        preg_match('/<p([^>]*)>(.*?)<\/p>/is', $paraText, $innerMatch);
        $pAttrs = $innerMatch[1] ?? '';
        $innerText = $innerMatch[2] ?? '';

        if (empty(trim(strip_tags($innerText)))) {
            return ['content' => $content, 'paragraph' => $paraIndex + 1, 'injected' => false, 'skipped' => true];
        }

        // วิธีที่ 1: หาจุดจบประโยคกลาง paragraph แล้วแทรกประโยคสั้นที่มี keyword
        // หาตำแหน่งจุด (.) หรือเครื่องหมายจบประโยคในเนื้อหา
        if (preg_match_all('/([ก-๙a-zA-Z0-9\)]+)([\.\?!。！？])\s*/u', $innerText, $sentenceMatches, PREG_OFFSET_CAPTURE)) {
            // มีหลายประโยค - เลือกแทรกหลังประโยคกลางๆ
            $totalSentences = count($sentenceMatches[0]);
            if ($totalSentences >= 2) {
                // เลือกตำแหน่งกลางๆ (ไม่ใช่ประโยคแรกหรือสุดท้าย)
                $targetSentenceIndex = min(1, $totalSentences - 2);
                $targetSentenceIndex = max(0, $targetSentenceIndex);

                $sentenceEnd = $sentenceMatches[0][$targetSentenceIndex];
                $insertPos = $sentenceEnd[1] + strlen($sentenceEnd[0]);

                // ประโยคสั้นๆ ที่แทรก keyword อย่างเป็นธรรมชาติ
                $naturalPhrases = [
                    " {$linkHtml} ก็เป็นอีกทางเลือกหนึ่ง",
                    " นอกจากนี้ {$linkHtml} ยังเป็นที่นิยม",
                    " รวมถึง {$linkHtml} ที่หลายคนชื่นชอบ",
                    " หลายคนยังนิยม {$linkHtml} อีกด้วย"
                ];
                $insertText = $naturalPhrases[array_rand($naturalPhrases)] . '.';

                // แทรกเข้าไปกลาง paragraph (สร้าง paragraph ใหม่โดยตรง ไม่ใช้ preg_replace)
                // ใช้ substr (byte-based) เพราะ preg_match returns byte offsets
                $newInnerText = substr($innerText, 0, $insertPos) . $insertText . substr($innerText, $insertPos);
                $newParaContent = '<p' . $pAttrs . '>' . $newInnerText . '</p>';

                $newContent = substr($content, 0, $para['start']) . $newParaContent . substr($content, $para['end']);

                return ['content' => $newContent, 'paragraph' => $paraIndex + 1, 'injected' => true];
            }
        }

        // วิธีที่ 2: มีประโยคเดียว - แทรกประโยคต่อเนื่องหลังเนื้อหาเดิม
        $plainText = trim(strip_tags($innerText));
        $lastChar = mb_substr($plainText, -1);

        // ถ้าไม่จบด้วยเครื่องหมายวรรคตอน ให้เพิ่มจุด
        $needPeriod = !in_array($lastChar, ['.', '!', '?', '。', '！', '？']);

        // ประโยคสั้นที่เริ่มต้นเป็นธรรมชาติ
        $shortPhrases = [
            ($needPeriod ? '. ' : ' ') . "ซึ่ง {$linkHtml} ก็เป็นตัวเลือกยอดนิยม",
            ($needPeriod ? '. ' : ' ') . "โดยเฉพาะ {$linkHtml} ที่ได้รับความสนใจ",
            ($needPeriod ? '. ' : ' ') . "เช่นเดียวกับ {$linkHtml} ที่หลายคนชอบ"
        ];
        $insertText = $shortPhrases[array_rand($shortPhrases)];

        // สร้าง paragraph ใหม่โดยตรง (ใช้ substr เพราะ positions เป็น byte offsets)
        $newParaContent = '<p' . $pAttrs . '>' . $innerText . $insertText . '</p>';
        $newContent = substr($content, 0, $para['start']) . $newParaContent . substr($content, $para['end']);

        return ['content' => $newContent, 'paragraph' => $paraIndex + 1, 'injected' => true];
    }

    /**
     * แทรก keyword ใน paragraph ที่ไม่มี link (ไม่สร้าง paragraph ใหม่)
     * ใช้เมื่อ paragraph ทั้งหมดมี link อยู่แล้ว - จะแทรกเข้าไปใน paragraph ที่เหมาะสมที่สุด
     */
    private function injectNewParagraphWithLink(string $content, string $keyword, string $url, string $title, int $targetParagraph): array {
        $linkHtml = '<a href="' . htmlspecialchars($url) . '"><strong>' . htmlspecialchars($keyword) . '</strong></a>';

        // หา paragraph ทั้งหมด (จับ attributes ด้วย)
        preg_match_all('/<p([^>]*)>(.*?)<\/p>/is', $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        if (empty($matches)) {
            // ไม่มี paragraph - แทรกท้ายบทความด้วยประโยคสมบูรณ์
            return [
                'content' => $content . " นอกจากนี้ {$linkHtml} ยังเป็นอีกทางเลือกที่น่าสนใจ",
                'paragraph' => 0,
                'injected' => true
            ];
        }

        // เลือก paragraph ที่จะแทรก (หลีกเลี่ยง paragraph แรกและสุดท้าย)
        $totalParagraphs = count($matches);
        $targetIndex = min($targetParagraph - 1, $totalParagraphs - 1);
        $targetIndex = max(0, $targetIndex);

        // ถ้ามีมากกว่า 2 paragraphs หลีกเลี่ยง paragraph แรก
        if ($totalParagraphs > 2 && $targetIndex === 0) {
            $targetIndex = 1;
        }

        $para = $matches[$targetIndex];
        $fullPara = $para[0][0];
        $pAttrs = $para[1][0]; // attributes ของ <p>
        $innerText = $para[2][0]; // เนื้อหาใน <p>
        $paraStart = $para[0][1];
        $paraEnd = $paraStart + strlen($fullPara);

        // หาตำแหน่งจุดจบประโยคในเนื้อหา
        if (preg_match_all('/([ก-๙a-zA-Z0-9\)]+)([\.\?!。！？])\s*/u', $innerText, $sentenceMatches, PREG_OFFSET_CAPTURE)) {
            $totalSentences = count($sentenceMatches[0]);
            if ($totalSentences >= 2) {
                // แทรกหลังประโยคกลางๆ
                $insertAfter = min(1, $totalSentences - 2);
                $sentenceEnd = $sentenceMatches[0][$insertAfter];
                $insertPos = $sentenceEnd[1] + strlen($sentenceEnd[0]);

                $naturalPhrases = [
                    " {$linkHtml} ก็เป็นอีกทางเลือกที่ดี.",
                    " สำหรับ {$linkHtml} ก็ได้รับความนิยมเช่นกัน.",
                    " รวมถึง {$linkHtml} ที่หลายคนชื่นชอบ."
                ];
                $insertText = $naturalPhrases[array_rand($naturalPhrases)];

                // ใช้ substr เพราะ $insertPos เป็น byte offset จาก preg_match
                $newInnerText = substr($innerText, 0, $insertPos) . $insertText . substr($innerText, $insertPos);
                $newParaContent = '<p' . $pAttrs . '>' . $newInnerText . '</p>';

                $newContent = substr($content, 0, $paraStart) . $newParaContent . substr($content, $paraEnd);
                return ['content' => $newContent, 'paragraph' => $targetIndex + 1, 'injected' => true];
            }
        }

        // Fallback: แทรกประโยคต่อเนื่องท้าย paragraph
        $plainText = trim(strip_tags($innerText));
        $lastChar = mb_substr($plainText, -1);
        $needPeriod = !in_array($lastChar, ['.', '!', '?', '。', '！', '？']);

        $phrases = [
            ($needPeriod ? '. ' : ' ') . "ซึ่ง {$linkHtml} ก็เป็นตัวเลือกที่ดี",
            ($needPeriod ? '. ' : ' ') . "โดย {$linkHtml} ก็ได้รับความนิยม",
            ($needPeriod ? '. ' : ' ') . "เช่นเดียวกับ {$linkHtml} ที่น่าสนใจ"
        ];
        $phrase = $phrases[array_rand($phrases)];

        $newParaContent = '<p' . $pAttrs . '>' . $innerText . $phrase . '</p>';
        $newContent = substr($content, 0, $paraStart) . $newParaContent . substr($content, $paraEnd);

        return ['content' => $newContent, 'paragraph' => $targetIndex + 1, 'injected' => true];
    }

    /**
     * เพิ่ม Outbound Link ที่ตำแหน่งกำหนด
     */
    private function addOutboundLinkAtPosition(string $content, int $siteId, int $targetParagraph, array $usedParagraphs): array {
        $outboundLinks = db()->fetchAll("
            SELECT id, url, anchor_text, title, use_count
            FROM outbound_links
            WHERE site_id = ? AND is_active = 1
            ORDER BY use_count ASC, RAND()
        ", [$siteId]);

        if (empty($outboundLinks)) {
            return ['added' => false, 'content' => $content];
        }

        $selectedLink = $outboundLinks[0];
        $anchor = $selectedLink['anchor_text'];
        $url = $selectedLink['url'];
        $linkId = $selectedLink['id'];
        $title = $selectedLink['title'] ?? $anchor;

        // ใส่ลิงก์ที่ตำแหน่งกำหนด
        $result = $this->insertLinkAtParagraph($content, $anchor, $url, $title, $targetParagraph, $usedParagraphs);

        if (!isset($result['skipped']) || !$result['skipped']) {
            // อัพเดทสถิติ
            db()->query("UPDATE outbound_links SET use_count = use_count + 1, last_used_at = NOW() WHERE id = ?", [$linkId]);

            return [
                'added' => true,
                'content' => $result['content'],
                'anchor' => $anchor,
                'url' => $url,
                'link_id' => $linkId,
                'paragraph' => $result['paragraph'] ?? 0,
                'injected' => $result['injected'] ?? false
            ];
        }

        return ['added' => false, 'content' => $content];
    }

    /**
     * Fallback: สร้าง Internal Links จาก keyword ที่พบในเนื้อหา (Legacy - ไม่ใช้แล้ว)
     */
    private function insertKeywordBasedLinks(string $content, array $articles, int $currentCount): array {
        $logs = [];
        $linksAdded = 0;
        $maxLinks = 5 - $currentCount;

        // สร้าง keyword map จากบทความเก่า
        $keywordMap = [];
        foreach ($articles as $art) {
            $kw = $art['primary_keyword'] ?? '';
            if (!empty($kw) && !empty($art['post_url'])) {
                // แยก keyword เป็นหลายรูปแบบ
                $variations = $this->generateKeywordVariations($kw);
                foreach ($variations as $var) {
                    if (mb_strlen($var) >= 3) {
                        $keywordMap[$var] = [
                            'url' => $art['post_url'],
                            'title' => $art['title'],
                            'original_keyword' => $kw
                        ];
                    }
                }
            }
        }

        // เรียงจาก keyword ยาวไปสั้น (เพื่อ match keyword ยาวก่อน)
        uksort($keywordMap, function($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });

        $usedUrls = [];

        foreach ($keywordMap as $keyword => $data) {
            if ($linksAdded >= $maxLinks) break;

            // ข้าม URL ที่ใช้แล้ว
            if (in_array($data['url'], $usedUrls)) continue;

            // ตรวจสอบว่า keyword มีอยู่ในเนื้อหา
            if (mb_stripos($content, $keyword) !== false) {
                // ตรวจสอบว่ายังไม่มี link อยู่แล้ว
                if (!preg_match('/<a[^>]*>' . preg_quote($keyword, '/') . '<\/a>/iu', $content)) {
                    $result = $this->insertLink($content, [
                        'anchor' => $keyword,
                        'url' => $data['url']
                    ]);

                    if ($result['success']) {
                        $content = $result['content'];
                        $linksAdded++;
                        $usedUrls[] = $data['url'];
                        $logs[] = "[Fallback] เพิ่มลิงก์: \"{$keyword}\" → {$data['url']}";
                    }
                }
            }
        }

        return [
            'content' => $content,
            'links_added' => $linksAdded,
            'logs' => $logs
        ];
    }

    /**
     * สร้าง keyword variations จาก keyword เดิม
     */
    private function generateKeywordVariations(string $keyword): array {
        $variations = [$keyword];

        // แยกคำ
        $words = preg_split('/\s+/', $keyword);

        // เพิ่ม variations
        if (count($words) >= 2) {
            // ใช้ 2 คำแรก
            $variations[] = implode(' ', array_slice($words, 0, 2));

            // ใช้ 2 คำสุดท้าย
            $variations[] = implode(' ', array_slice($words, -2));

            // แต่ละคำที่ยาวพอ
            foreach ($words as $word) {
                if (mb_strlen($word) >= 4) {
                    $variations[] = $word;
                }
            }
        }

        // เพิ่ม common related keywords
        $commonKeywords = [
            'โบนัส' => ['รับโบนัส', 'โบนัสฟรี', 'โปรโมชั่น'],
            'ทดลอง' => ['ทดลองใช้ฟรี', 'ทดลองได้เลย'],
            'รีวิว' => ['รีวิวจากผู้ใช้จริง', 'รีวิวละเอียด'],
        ];

        // หา related keywords
        foreach ($commonKeywords as $base => $related) {
            if (mb_stripos($keyword, $base) !== false) {
                $variations = array_merge($variations, $related);
            }
        }

        // ลบ duplicate
        return array_unique($variations);
    }

    /**
     * เพิ่ม contextual links โดยหา common phrases ในเนื้อหา
     */
    private function insertContextualLinks(string $content, array $articles, int $currentCount): array {
        $logs = [];
        $linksAdded = 0;
        $maxLinks = 5 - $currentCount;

        // Common anchor phrases ที่มักพบในบทความ
        $commonAnchors = [
            'อ่านเพิ่มเติม',
            'ดูรายละเอียด',
            'คลิกที่นี่',
            'เรียนรู้เพิ่มเติม',
            'สมัครสมาชิก',
            'ทดลองเล่น',
            'เล่นเกม',
            'รับโบนัส',
        ];

        $usedUrls = [];

        foreach ($articles as $index => $art) {
            if ($linksAdded >= $maxLinks) break;
            if (in_array($art['post_url'], $usedUrls)) continue;

            foreach ($commonAnchors as $anchor) {
                if (mb_stripos($content, $anchor) !== false) {
                    $result = $this->insertLink($content, [
                        'anchor' => $anchor,
                        'url' => $art['post_url']
                    ]);

                    if ($result['success']) {
                        $content = $result['content'];
                        $linksAdded++;
                        $usedUrls[] = $art['post_url'];
                        $logs[] = "[Contextual] เพิ่มลิงก์: \"{$anchor}\" → {$art['post_url']}";
                        break; // ใช้ได้แค่ 1 anchor ต่อ article
                    }
                }
            }
        }

        return [
            'content' => $content,
            'links_added' => $linksAdded,
            'logs' => $logs
        ];
    }

    /**
     * ดึงบทความที่เผยแพร่แล้ว
     */
    private function getPublishedArticles(int $siteId): array {
        $articles = db()->fetchAll("
            SELECT id, title, post_url, primary_keyword, topic,
                   SUBSTRING(content, 1, 500) as content_preview,
                   published_at
            FROM articles
            WHERE site_id = ?
              AND status = 'published'
              AND post_url IS NOT NULL
              AND post_url != ''
            ORDER BY published_at DESC
            LIMIT 15
        ", [$siteId]);

        // Shorten primary_keyword for anchor text — ใช้แค่ keyword สั้นๆ
        foreach ($articles as &$article) {
            $article['primary_keyword'] = $this->shortenKeyword($article['primary_keyword']);
        }
        unset($article);

        return $articles;
    }

    /**
     * ตัด keyword ให้สั้น ใช้เป็น anchor text
     * ตัดส่วนหลัง : — | – และจำกัดความยาว
     */
    private function shortenKeyword(string $keyword): string {
        // ตัดส่วนหลัง separators (: — | – &#8211;)
        $keyword = preg_replace('/\s*[:\-—–|]\s.*$/u', '', $keyword);
        $keyword = preg_replace('/\s*&#8211;\s.*$/u', '', $keyword);

        // ตัดส่วนที่ไม่ใช่ keyword หลัก
        $keyword = preg_replace('/\s+(รีวิว|วิธี|สมัคร|ทางเข้า|เว็บแนะนำ|คู่มือ|ครบวงจร|ระบบ|รวม|เกมชื่อดัง|ที่ครบ|ปี\s*\d{4}).*/u', '', $keyword);

        // จำกัดไม่เกิน 40 ตัวอักษร — ตัดที่ช่องว่างก่อนถึง limit
        if (mb_strlen($keyword) > 40) {
            $keyword = mb_substr($keyword, 0, 40);
            $lastSpace = mb_strrpos($keyword, ' ');
            if ($lastSpace > 10) {
                $keyword = mb_substr($keyword, 0, $lastSpace);
            }
        }

        return trim($keyword);
    }

    /**
     * ใช้ AI วิเคราะห์และแนะนำ Internal Links
     */
    private function aiAnalyzeForLinks(string $content, array $articles, string $primaryKeyword, array $site): array {
        // Get language
        $langCode = $site['language_code'] ?? 'th';
        $langNames = [
            'th' => 'ภาษาไทย', 'vn' => 'tiếng Việt', 'en' => 'English',
            'id' => 'Bahasa Indonesia', 'my' => 'Bahasa Malaysia', 'km' => 'ភាសាខ្មែរ',
            'zh' => '中文', 'ja' => '日本語', 'ko' => '한국어'
        ];
        $langName = $langNames[$langCode] ?? 'English';

        // เตรียมข้อมูลบทความเก่า
        $articlesData = [];
        foreach ($articles as $art) {
            $articlesData[] = [
                'id' => $art['id'],
                'title' => $art['title'],
                'url' => $art['post_url'],
                'keyword' => $art['primary_keyword'],
                'preview' => mb_substr(strip_tags($art['content_preview']), 0, 200)
            ];
        }

        $articlesJson = json_encode($articlesData, JSON_UNESCAPED_UNICODE);

        // ตัดเนื้อหาให้สั้นลงสำหรับ AI
        $contentPreview = mb_substr(strip_tags($content), 0, 2000);

        $prompt = <<<PROMPT
คุณเป็น SEO Expert ช่วยวิเคราะห์และแนะนำ Internal Links สำหรับบทความ{$langName}ใหม่

## บทความใหม่ (กำลังจะโพสต์)
Primary Keyword: {$primaryKeyword}
เนื้อหา (ตัวอย่าง):
{$contentPreview}

## บทความเก่าที่เผยแพร่แล้ว (สามารถลิงก์ไปได้)
{$articlesJson}

## งานของคุณ
1. วิเคราะห์เนื้อหาบทความใหม่
2. หาจุดที่เหมาะสมสำหรับใส่ Internal Links ไปยังบทความเก่า
3. เลือก anchor text ที่เป็นธรรมชาติ (ไม่จำเป็นต้องเป็น exact keyword)
4. แนะนำ 3-5 links ที่เหมาะสมที่สุด

## เกณฑ์การเลือก
- Anchor text ต้องอยู่ในเนื้อหาจริงๆ หรือสามารถแทรกได้อย่างเป็นธรรมชาติ
- เลือกบทความที่เกี่ยวข้องกับบริบทของประโยคนั้น
- หลีกเลี่ยง anchor text ที่ดูเหมือน spam
- กระจาย links ไม่ให้อยู่รวมกันที่เดียว

## ตอบเป็น JSON เท่านั้น
{
  "links": [
    {
      "article_id": 1,
      "url": "https://...",
      "anchor": "คำหรือวลีที่จะเป็น anchor text",
      "context": "ประโยคหรือบริบทที่จะใส่ link",
      "reason": "เหตุผลที่เลือก link นี้"
    }
  ],
  "analysis": "สรุปการวิเคราะห์สั้นๆ"
}
PROMPT;

        // 🎯 QUALITY MODE: ใช้ tier3 (Claude 3.5 Sonnet) สำหรับ internal link analysis คุณภาพสูง
        $result = $this->ai->execute('content_gap', $prompt, ['max_tokens' => 2500]);

        if (empty($result['content'])) {
            return ['links' => []];
        }

        // Parse response
        $responseContent = $result['content'];
        $responseContent = preg_replace('/^```json\s*/i', '', $responseContent);
        $responseContent = preg_replace('/^```\s*/m', '', $responseContent);
        $responseContent = trim($responseContent);

        $parsed = json_decode($responseContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            if (preg_match('/\{[\s\S]*\}/', $responseContent, $matches)) {
                $parsed = json_decode($matches[0], true);
            }
        }

        return is_array($parsed) ? $parsed : ['links' => []];
    }

    /**
     * แทรก link ลงในเนื้อหา (ปรับปรุงให้ robust มากขึ้น)
     * ⚠️ ไม่ใส่ลิงก์ใน Heading (H1-H6) - ใส่เฉพาะในเนื้อหาปกติเท่านั้น
     */
    private function insertLink(string $content, array $link): array {
        $anchor = trim($link['anchor'] ?? '');
        $url = trim($link['url'] ?? '');

        if (empty($anchor) || empty($url) || mb_strlen($anchor) < 2) {
            return ['success' => false, 'content' => $content];
        }

        // ตรวจสอบว่า anchor มีอยู่ในเนื้อหา (case insensitive)
        if (mb_stripos($content, $anchor) === false) {
            return ['success' => false, 'content' => $content];
        }

        // ตรวจสอบว่า URL นี้ยังไม่มีในเนื้อหา
        if (mb_stripos($content, $url) !== false) {
            return ['success' => false, 'content' => $content];
        }

        // ตรวจสอบว่า anchor ยังไม่เป็น link อยู่แล้ว
        if (preg_match('/<a[^>]*>[^<]*' . preg_quote($anchor, '/') . '[^<]*<\/a>/iu', $content)) {
            return ['success' => false, 'content' => $content];
        }

        // หาตำแหน่งที่เหมาะสมสำหรับใส่ link
        $searchStart = 0;
        $foundPos = false;

        while (($pos = mb_stripos($content, $anchor, $searchStart)) !== false) {
            // ตรวจสอบว่าตำแหน่งนี้เหมาะสมหรือไม่
            if ($this->isValidLinkPosition($content, $pos, $anchor)) {
                $foundPos = $pos;
                break;
            }
            $searchStart = $pos + mb_strlen($anchor);
        }

        if ($foundPos === false) {
            return ['success' => false, 'content' => $content];
        }

        // ดึง anchor text ตามที่ปรากฏจริงในเนื้อหา (เพื่อรักษา case เดิม)
        $actualAnchor = mb_substr($content, $foundPos, mb_strlen($anchor));

        // สร้าง link HTML
        $linkHtml = '<a href="' . htmlspecialchars($url) . '" title="' . htmlspecialchars($actualAnchor) . '"><strong>' . $actualAnchor . '</strong></a>';

        // แทรก link
        $newContent = mb_substr($content, 0, $foundPos) . $linkHtml . mb_substr($content, $foundPos + mb_strlen($anchor));

        return [
            'success' => true,
            'content' => $newContent
        ];
    }

    /**
     * ตรวจสอบว่าตำแหน่งนี้เหมาะสมสำหรับใส่ link หรือไม่
     * ❌ ไม่ใส่ใน: H1-H6, title, <a> tag, attribute
     * ✅ ใส่ใน: <p>, <li>, <td>, <span>, เนื้อหาปกติ
     */
    private function isValidLinkPosition(string $content, int $pos, string $anchor): bool {
        $beforePos = mb_substr($content, 0, $pos);

        // 1. ตรวจสอบว่าอยู่ใน tag attribute หรือไม่ (เช่น href="...", class="...")
        $lastOpenTag = mb_strrpos($beforePos, '<');
        $lastCloseTag = mb_strrpos($beforePos, '>');

        if ($lastOpenTag !== false && ($lastCloseTag === false || $lastOpenTag > $lastCloseTag)) {
            // อยู่ระหว่าง < และ > (ใน tag definition) - ข้าม
            return false;
        }

        // 2. ตรวจสอบว่าอยู่ใน Heading (H1-H6) หรือไม่
        // หา opening tag ล่าสุดก่อนตำแหน่งนี้
        if (preg_match_all('/<(h[1-6]|\/h[1-6])[^>]*>/i', $beforePos, $matches, PREG_OFFSET_CAPTURE)) {
            $lastMatch = end($matches[0]);
            $lastTag = strtolower($lastMatch[0]);

            // ถ้า tag ล่าสุดเป็น opening heading tag (ไม่ใช่ closing)
            if (preg_match('/<h[1-6]/i', $lastTag)) {
                // ตรวจสอบว่ามี closing tag หลังตำแหน่งนี้หรือไม่
                $afterPos = mb_substr($content, $pos);
                if (preg_match('/<\/h[1-6]>/i', $afterPos)) {
                    // อยู่ใน Heading - ไม่ใส่ link
                    return false;
                }
            }
        }

        // 3. ตรวจสอบว่าอยู่ใน <a> tag หรือไม่
        if (preg_match_all('/<(a |\/a)[^>]*>/i', $beforePos, $matches, PREG_OFFSET_CAPTURE)) {
            $lastMatch = end($matches[0]);
            $lastTag = strtolower($lastMatch[0]);

            if (strpos($lastTag, '<a ') === 0) {
                $afterPos = mb_substr($content, $pos);
                if (preg_match('/<\/a>/i', $afterPos)) {
                    // อยู่ใน <a> tag - ไม่ใส่ link ซ้อน
                    return false;
                }
            }
        }

        // 4. ตรวจสอบว่าอยู่ใน <title> tag หรือไม่
        if (preg_match_all('/<(title|\/title)[^>]*>/i', $beforePos, $matches, PREG_OFFSET_CAPTURE)) {
            $lastMatch = end($matches[0]);
            $lastTag = strtolower($lastMatch[0]);

            if (strpos($lastTag, '<title') === 0) {
                $afterPos = mb_substr($content, $pos);
                if (preg_match('/<\/title>/i', $afterPos)) {
                    return false;
                }
            }
        }

        // ผ่านทุกเงื่อนไข - ตำแหน่งนี้เหมาะสม
        return true;
    }

    /**
     * เพิ่มลิงก์หน้าแรก (บังคับต้องมี)
     * ถ้า keyword ไม่พบในเนื้อหา จะแทรก keyword พร้อมลิงก์ในตำแหน่งที่เหมาะสม
     *
     * @param string $content เนื้อหาบทความ
     * @param string $homepageUrl URL หน้าแรก
     * @param string $homepageKeyword Keyword หลักของหน้าแรก
     * @return array [content, anchor, injected]
     */
    private function addHomepageLinkMandatory(string $content, string $homepageUrl, string $homepageKeyword): array {
        // 1. ตรวจสอบว่า URL หน้าแรกยังไม่มีในเนื้อหา
        if (mb_stripos($content, $homepageUrl) !== false) {
            return [
                'content' => $content,
                'anchor' => $homepageKeyword,
                'injected' => false,
                'already_exists' => true
            ];
        }

        // 2. พยายามหา keyword ในเนื้อหาและทำลิงก์
        if (mb_stripos($content, $homepageKeyword) !== false) {
            $result = $this->insertLink($content, [
                'anchor' => $homepageKeyword,
                'url' => $homepageUrl
            ]);

            if ($result['success']) {
                return [
                    'content' => $result['content'],
                    'anchor' => $homepageKeyword,
                    'injected' => false
                ];
            }
        }

        // 3. ถ้าไม่พบ keyword - แทรก keyword พร้อมลิงก์ในเนื้อหา
        $linkHtml = '<a href="' . htmlspecialchars($homepageUrl) . '" title="' . htmlspecialchars($homepageKeyword) . '"><strong>' . htmlspecialchars($homepageKeyword) . '</strong></a>';

        // หาตำแหน่งที่เหมาะสมสำหรับแทรก (หลัง </p> แรก หรือก่อน </p> สุดท้าย)
        $injectedContent = $this->injectLinkInContent($content, $linkHtml, $homepageKeyword);

        return [
            'content' => $injectedContent,
            'anchor' => $homepageKeyword,
            'injected' => true
        ];
    }

    /**
     * แทรกลิงก์ในเนื้อหาอย่างเป็นธรรมชาติ (ใช้สำหรับ Homepage Link)
     * จะหาจุดจบประโยคกลาง paragraph แล้วแทรกประโยคสั้นที่มี keyword
     * @param string $content เนื้อหาบทความ
     * @param string $linkHtml HTML ของลิงก์
     * @param string $keyword Keyword ที่จะใช้
     * @return string เนื้อหาที่แทรกลิงก์แล้ว
     */
    private function injectLinkInContent(string $content, string $linkHtml, string $keyword): string {
        // หา paragraph ทั้งหมด (จับ attributes ด้วย)
        preg_match_all('/<p([^>]*)>(.*?)<\/p>/is', $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        if (empty($matches)) {
            // ไม่มี paragraph - แทรกท้ายเนื้อหา
            return $content . " นอกจากนี้ {$linkHtml} ยังเป็นแหล่งข้อมูลที่ดี";
        }

        // เลือก paragraph ที่ 2-3 (ไม่ใช่ paragraph แรก)
        $targetIndex = min(2, count($matches) - 1);
        $targetIndex = max(1, $targetIndex);

        // ถ้ามี paragraph เดียว ใช้ paragraph นั้น
        if (count($matches) === 1) {
            $targetIndex = 0;
        }

        $para = $matches[$targetIndex];
        $fullPara = $para[0][0];
        $pAttrs = $para[1][0]; // attributes ของ <p>
        $innerText = $para[2][0]; // เนื้อหาใน <p>
        $paraStart = $para[0][1];
        $paraEnd = $paraStart + strlen($fullPara);

        // หาจุดจบประโยคในเนื้อหา
        if (preg_match_all('/([ก-๙a-zA-Z0-9\)]+)([\.\?!。！？])\s*/u', $innerText, $sentenceMatches, PREG_OFFSET_CAPTURE)) {
            $totalSentences = count($sentenceMatches[0]);
            if ($totalSentences >= 2) {
                // แทรกหลังประโยคแรกหรือกลางๆ
                $insertAfter = min(0, $totalSentences - 2);
                $sentenceEnd = $sentenceMatches[0][$insertAfter];
                $insertPos = $sentenceEnd[1] + strlen($sentenceEnd[0]);

                $naturalPhrases = [
                    " {$linkHtml} เป็นอีกแหล่งข้อมูลที่น่าสนใจ.",
                    " หลายคนยังนิยมเข้าชม {$linkHtml} อีกด้วย.",
                    " ซึ่ง {$linkHtml} ก็มีข้อมูลที่เป็นประโยชน์."
                ];
                $insertText = $naturalPhrases[array_rand($naturalPhrases)];

                // ใช้ substr (byte-based) เพราะ preg_match returns byte offsets
                $newInnerText = substr($innerText, 0, $insertPos) . $insertText . substr($innerText, $insertPos);
                $newParaContent = '<p' . $pAttrs . '>' . $newInnerText . '</p>';

                return substr($content, 0, $paraStart) . $newParaContent . substr($content, $paraEnd);
            }
        }

        // Fallback: แทรกประโยคต่อเนื่องท้าย paragraph ที่เลือก
        $plainText = trim(strip_tags($innerText));
        $lastChar = mb_substr($plainText, -1);
        $needPeriod = !in_array($lastChar, ['.', '!', '?', '。', '！', '？']);

        $phrases = [
            ($needPeriod ? '. ' : ' ') . "โดย {$linkHtml} ก็เป็นแหล่งข้อมูลที่ดี",
            ($needPeriod ? '. ' : ' ') . "เช่นเดียวกับ {$linkHtml} ที่ให้ข้อมูลครบถ้วน",
            ($needPeriod ? '. ' : ' ') . "นอกจากนี้ {$linkHtml} ยังมีข้อมูลเพิ่มเติม"
        ];
        $phrase = $phrases[array_rand($phrases)];

        // ใช้ substr (byte-based) เพราะ preg_match returns byte offsets
        $newParaContent = '<p' . $pAttrs . '>' . $innerText . $phrase . '</p>';
        return substr($content, 0, $paraStart) . $newParaContent . substr($content, $paraEnd);
    }

    /**
     * เพิ่มลิงก์หน้าแรกด้วย keyword หลักของเว็บ (เวอร์ชันเดิม - ไม่บังคับ)
     * @deprecated ใช้ addHomepageLinkMandatory แทน
     * @param string $content เนื้อหาบทความ
     * @param string $homepageUrl URL หน้าแรก
     * @param string $siteName ชื่อเว็บ
     * @param string|null $homepageKeyword Keyword หลักของหน้าแรก (ถ้ามี)
     */
    private function addHomepageLink(string $content, string $homepageUrl, string $siteName, ?string $homepageKeyword = null): array {
        // สร้างรายการ anchor text โดยให้ homepage_keyword เป็นลำดับแรก (ถ้ามี)
        $anchors = [];

        // 1. ใช้ homepage_keyword เป็น anchor หลัก (ถ้ามี)
        if (!empty($homepageKeyword)) {
            $anchors[] = $homepageKeyword;

            // เพิ่ม variations ของ homepage_keyword
            $kwWords = explode(' ', $homepageKeyword);
            if (count($kwWords) >= 2) {
                // ใช้ 2 คำแรก
                $anchors[] = implode(' ', array_slice($kwWords, 0, 2));
            }
        }

        // 2. Fallback anchors (ถ้า homepage_keyword ไม่พบในเนื้อหา)
        $fallbackAnchors = ['หน้าแรก', 'เว็บไซต์หลัก', 'คลิกที่นี่', 'อ่านเพิ่มเติม', 'ดูรายละเอียด', 'เว็บไซต์'];
        $anchors = array_merge($anchors, $fallbackAnchors);

        foreach ($anchors as $anchor) {
            if (mb_stripos($content, $anchor) !== false) {
                $result = $this->insertLink($content, [
                    'anchor' => $anchor,
                    'url' => $homepageUrl
                ]);

                if ($result['success']) {
                    return [
                        'added' => true,
                        'content' => $result['content'],
                        'anchor' => $anchor,
                        'is_primary_keyword' => ($anchor === $homepageKeyword)
                    ];
                }
            }
        }

        return ['added' => false, 'content' => $content, 'anchor' => ''];
    }

    /**
     * เพิ่มลิงค์ขาออก (Outbound Link) - สุ่ม 1 ลิงค์จากรายการที่ตั้งค่าไว้
     * @param string $content เนื้อหาบทความ
     * @param int $siteId Site ID
     * @return array [added, content, anchor, url, link_id]
     */
    private function addOutboundLink(string $content, int $siteId): array {
        // ดึงลิงค์ขาออกที่ active ของเว็บนี้
        $outboundLinks = db()->fetchAll("
            SELECT id, url, anchor_text, title, use_count
            FROM outbound_links
            WHERE site_id = ? AND is_active = 1
            ORDER BY use_count ASC, RAND()
        ", [$siteId]);

        if (empty($outboundLinks)) {
            return ['added' => false, 'content' => $content, 'anchor' => '', 'url' => ''];
        }

        // สุ่มเลือก 1 ลิงค์ (เลือกตัวที่ใช้น้อยที่สุดก่อน)
        $selectedLink = $outboundLinks[0];

        $anchor = $selectedLink['anchor_text'];
        $url = $selectedLink['url'];
        $linkId = $selectedLink['id'];

        // ตรวจสอบว่า anchor text มีอยู่ในเนื้อหาหรือไม่
        if (mb_stripos($content, $anchor) !== false) {
            // พบ anchor ในเนื้อหา - แทรกลิงค์
            $result = $this->insertLink($content, [
                'anchor' => $anchor,
                'url' => $url
            ]);

            if ($result['success']) {
                // อัพเดทสถิติการใช้งาน
                db()->query("
                    UPDATE outbound_links
                    SET use_count = use_count + 1, last_used_at = NOW()
                    WHERE id = ?
                ", [$linkId]);

                return [
                    'added' => true,
                    'content' => $result['content'],
                    'anchor' => $anchor,
                    'url' => $url,
                    'link_id' => $linkId
                ];
            }
        }

        // ถ้าไม่พบ anchor ในเนื้อหา - ข้ามไป (ไม่สร้าง section ท้ายบทความ)
        return ['added' => false, 'content' => $content, 'anchor' => $anchor, 'url' => $url];
    }

    /**
     * สร้างส่วน "แหล่งอ้างอิง" สำหรับลิงค์ขาออก
     */
    private function buildOutboundReferenceSection(array $link): string {
        $url = htmlspecialchars($link['url']);
        $anchor = htmlspecialchars($link['anchor_text']);
        $title = htmlspecialchars($link['title'] ?? $link['anchor_text']);

        return <<<HTML

<div class="outbound-reference" style="margin-top: 30px; padding: 15px 20px; background: #f8f9fa; border-radius: 8px; border-left: 3px solid #6c757d;">
    <p style="margin: 0; color: #495057; font-size: 0.95em;">
        <i class="fas fa-external-link-alt" style="margin-right: 8px; color: #6c757d;"></i>
        <strong>อ่านเพิ่มเติม:</strong>
        <a href="{$url}" target="_blank" rel="noopener" title="{$title}" style="color: #007bff; text-decoration: none; margin-left: 5px;">{$anchor}</a>
    </p>
</div>
HTML;
    }

    /**
     * สร้างส่วนบทความที่เกี่ยวข้อง
     */
    private function buildRelatedArticlesSection(array $articles, string $currentKeyword): array {
        // เลือกบทความที่เกี่ยวข้องมากที่สุด (ไม่เกิน 3)
        $selected = array_slice($articles, 0, 3);

        $html = "\n\n<div class=\"related-articles\" style=\"margin-top: 40px; padding: 25px; background: linear-gradient(135deg, #f5f7fa 0%, #e4e8eb 100%); border-radius: 12px; border-left: 4px solid #007bff;\">\n";
        $html .= "<h3 style=\"margin: 0 0 20px 0; color: #333; font-size: 1.3em;\">บทความที่เกี่ยวข้อง</h3>\n";
        $html .= "<ul style=\"margin: 0; padding: 0; list-style: none;\">\n";

        foreach ($selected as $article) {
            $title = htmlspecialchars($article['title']);
            $url = htmlspecialchars($article['post_url']);
            $html .= "<li style=\"margin-bottom: 12px; padding-left: 20px; position: relative;\">";
            $html .= "<span style=\"position: absolute; left: 0; color: #007bff;\">→</span>";
            $html .= "<a href=\"{$url}\" style=\"color: #333; text-decoration: none; transition: color 0.2s;\" onmouseover=\"this.style.color='#007bff'\" onmouseout=\"this.style.color='#333'\">{$title}</a>";
            $html .= "</li>\n";
        }

        $html .= "</ul>\n</div>";

        return [
            'html' => $html,
            'count' => count($selected)
        ];
    }

    /**
     * สร้างปุ่ม CTA กลับหน้าแรกด้วย keyword หลัก
     * @param string $homepageUrl URL หน้าแรก
     * @param string $siteName ชื่อเว็บ
     * @param string|null $homepageKeyword Keyword หลักของหน้าแรก (ถ้ามี)
     */
    private function buildCtaButton(string $homepageUrl, string $siteName, ?string $homepageKeyword = null): string {
        $url = htmlspecialchars($homepageUrl);
        $name = htmlspecialchars($siteName);

        // ใช้ homepage_keyword เป็น anchor text ถ้ามี ไม่งั้นใช้ชื่อเว็บ
        $buttonText = !empty($homepageKeyword)
            ? htmlspecialchars($homepageKeyword)
            : "กลับไปหน้าแรก {$name}";

        // Title attribute ใช้ keyword เพื่อช่วย SEO
        $titleAttr = !empty($homepageKeyword)
            ? htmlspecialchars($homepageKeyword)
            : "หน้าแรก {$name}";

        return <<<HTML

<div style="text-align: center; margin-top: 30px; padding: 20px;">
    <a href="{$url}" title="{$titleAttr}" style="display: inline-block; padding: 15px 35px; background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); color: #fff; text-decoration: none; border-radius: 30px; font-weight: bold; box-shadow: 0 4px 15px rgba(0,123,255,0.3); transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(0,123,255,0.4)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(0,123,255,0.3)';">
        {$buttonText}
    </a>
</div>
HTML;
    }

    /**
     * อัพเดท Internal Links สำหรับบทความเก่า (backlinks)
     * เมื่อมีบทความใหม่ ให้ไปอัพเดทบทความเก่าให้ลิงก์มาหาบทความใหม่
     */
    public function updateBacklinks(int $newArticleId, int $siteId): array {
        $logs = [];
        $updated = 0;

        // ดึงข้อมูลบทความใหม่
        $newArticle = db()->fetchOne("
            SELECT id, title, post_url, primary_keyword, content
            FROM articles WHERE id = ?
        ", [$newArticleId]);

        if (!$newArticle || empty($newArticle['post_url'])) {
            return ['updated' => 0, 'logs' => ['ไม่พบบทความใหม่']];
        }

        // ดึงบทความเก่า
        $oldArticles = db()->fetchAll("
            SELECT id, title, content, wp_post_id
            FROM articles
            WHERE site_id = ?
              AND id != ?
              AND status = 'published'
            ORDER BY published_at DESC
            LIMIT 10
        ", [$siteId, $newArticleId]);

        $keyword = $newArticle['primary_keyword'];
        $url = $newArticle['post_url'];

        foreach ($oldArticles as $old) {
            // ตรวจสอบว่ามี keyword ในเนื้อหาเก่าหรือไม่
            if (mb_stripos($old['content'], $keyword) !== false) {
                // ตรวจสอบว่ายังไม่มี link อยู่แล้ว
                if (mb_stripos($old['content'], $url) === false) {
                    // TODO: อัพเดทเนื้อหาผ่าน WordPress API
                    $logs[] = "พบ '{$keyword}' ในบทความ: {$old['title']} (รอ update)";
                    $updated++;
                }
            }
        }

        return [
            'updated' => $updated,
            'logs' => $logs
        ];
    }

    /**
     * เลือก Homepage Keyword ถัดไปแบบ round-robin
     * ถ้ามีหลายคำ (แยกบรรทัด) จะไล่ใช้ทีละคำ
     */
    private function getNextHomepageKeyword(array $site): ?string {
        $raw = $site['homepage_keyword'] ?? '';
        if (empty(trim($raw))) {
            return null;
        }

        // แยกคำด้วย newline หรือ comma
        $keywords = array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $raw))));
        if (empty($keywords)) {
            return null;
        }

        // ถ้ามีคำเดียว ใช้เลย
        if (count($keywords) === 1) {
            return $keywords[0];
        }

        // Round-robin: ใช้ index ที่บันทึกไว้
        $currentIndex = (int)($site['homepage_keyword_index'] ?? 0);
        $keyword = $keywords[$currentIndex % count($keywords)];

        // อัพเดท index สำหรับครั้งถัดไป
        $nextIndex = ($currentIndex + 1) % count($keywords);
        try {
            db()->update('sites', [
                'homepage_keyword_index' => $nextIndex
            ], 'id = ?', [$site['id']]);
        } catch (\Exception $e) {
            // ไม่ให้ error ทำให้กระบวนการหลักล้มเหลว
        }

        return $keyword;
    }
}

/**
 * Helper function
 */
function internalLinkBuilder(): InternalLinkBuilder {
    return new InternalLinkBuilder();
}
