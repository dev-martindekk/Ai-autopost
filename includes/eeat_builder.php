<?php
/**
 * AI AutoPost SEO System - EEAT Builder
 * =====================================
 * Generates brand pages and EEAT-focused content
 * About Us, Terms, Privacy, Editorial Policy, etc.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai_orchestrator.php';

class EEATBuilder {
    private $ai;

    // Page types this builder can generate
    const PAGE_ABOUT = 'about';
    const PAGE_TERMS = 'terms';
    const PAGE_PRIVACY = 'privacy';
    const PAGE_CONTACT = 'contact';
    const PAGE_EDITORIAL = 'editorial_policy';

    public function __construct() {
        $this->ai = aiOrchestrator();
    }

    /**
     * Generate a brand page
     */
    public function generatePage(string $pageType, array $siteInfo): array {
        $generators = [
            self::PAGE_ABOUT => 'generateAboutPage',
            self::PAGE_TERMS => 'generateTermsPage',
            self::PAGE_PRIVACY => 'generatePrivacyPage',
            self::PAGE_CONTACT => 'generateContactPage',
            self::PAGE_EDITORIAL => 'generateEditorialPolicyPage'
        ];

        if (!isset($generators[$pageType])) {
            return ['success' => false, 'message' => 'Unknown page type'];
        }

        $method = $generators[$pageType];
        return $this->$method($siteInfo);
    }

    /**
     * Generate About Us page
     */
    private function generateAboutPage(array $site): array {
        $siteName = $site['name'] ?? 'Our Website';
        $topic = $site['main_topic'] ?? 'entertainment';

        $customPrompt = getPromptTemplate('eeat_about', '');
        if ($customPrompt) {
            $prompt = str_replace(
                ['{site_name}', '{topic}'],
                [$siteName, $topic],
                $customPrompt
            );
            return $this->generateWithAI($prompt, 'About Us');
        }

        $description = $site['description'] ?? 'ข้อมูลและบทความที่เป็นประโยชน์';

        $prompt = <<<PROMPT
Create a professional "About Us" page for a Thai website about {$description}.

SITE NAME: {$siteName}
TOPIC: {$topic}

REQUIREMENTS:
1. Write in Thai language
2. Professional but friendly tone
3. Build trust and credibility (EEAT)
4. Explain the site's mission and values
5. Mention commitment to:
   - Accurate, researched information
   - Responsible entertainment advice
   - User safety and privacy
   - Regular content updates
6. Include team/editorial expertise (can be general)
7. Add founding story/motivation
8. 500-800 words
9. Proper HTML structure (H1, H2, p, ul)

DO NOT:
- Make unrealistic promises
- Promote gambling directly
- Include specific business addresses (leave placeholder)

OUTPUT: HTML content only, no markdown.
PROMPT;

        return $this->generateWithAI($prompt, 'About Us');
    }

    /**
     * Generate Terms of Service page
     */
    private function generateTermsPage(array $site): array {
        $siteName = $site['name'] ?? 'Our Website';
        $siteUrl = $site['base_url'] ?? 'https://example.com';

        $customPrompt = getPromptTemplate('eeat_terms', '');
        if ($customPrompt) {
            $prompt = str_replace(
                ['{site_name}', '{site_url}'],
                [$siteName, $siteUrl],
                $customPrompt
            );
            return $this->generateWithAI($prompt, 'Terms of Service');
        }

        $prompt = <<<PROMPT
Create a "Terms of Service" page for a Thai website.

SITE NAME: {$siteName}
SITE URL: {$siteUrl}

REQUIREMENTS:
1. Write in Thai language
2. Legal but readable tone
3. Cover standard terms:
   - Acceptance of terms
   - Use of the website
   - User responsibilities
   - Intellectual property
   - Disclaimer of warranties
   - Limitation of liability
   - Age requirement (18+)
   - Content accuracy disclaimer
   - Third-party links disclaimer
   - Modification of terms
   - Governing law (Thailand)
   - Contact information (placeholder)
4. Clear section headings
5. 800-1200 words
6. Proper HTML structure

NOTE: This is an information website, not a gambling platform itself.

OUTPUT: HTML content only, no markdown.
PROMPT;

        return $this->generateWithAI($prompt, 'Terms of Service');
    }

    /**
     * Generate Privacy Policy page
     */
    private function generatePrivacyPage(array $site): array {
        $siteName = $site['name'] ?? 'Our Website';
        $siteUrl = $site['base_url'] ?? 'https://example.com';

        $customPrompt = getPromptTemplate('eeat_privacy', '');
        if ($customPrompt) {
            $prompt = str_replace(
                ['{site_name}', '{site_url}'],
                [$siteName, $siteUrl],
                $customPrompt
            );
            return $this->generateWithAI($prompt, 'Privacy Policy');
        }

        $prompt = <<<PROMPT
Create a "Privacy Policy" page for a Thai website, compliant with PDPA (Thailand's Personal Data Protection Act).

SITE NAME: {$siteName}
SITE URL: {$siteUrl}

REQUIREMENTS:
1. Write in Thai language
2. Professional legal tone
3. PDPA compliant structure:
   - Data controller information
   - Types of data collected
   - Purpose of data collection
   - Legal basis for processing
   - Data retention period
   - Data sharing with third parties
   - User rights under PDPA
   - Cookies policy
   - Security measures
   - Children's privacy (18+ only)
   - Changes to policy
   - Contact information (placeholder)
4. Clear section headings
5. 1000-1500 words
6. Proper HTML structure

OUTPUT: HTML content only, no markdown.
PROMPT;

        return $this->generateWithAI($prompt, 'Privacy Policy');
    }

    /**
     * Generate Contact page
     */
    private function generateContactPage(array $site): array {
        $siteName = $site['name'] ?? 'Our Website';

        $customPrompt = getPromptTemplate('eeat_contact', '');
        if ($customPrompt) {
            $prompt = str_replace(
                ['{site_name}'],
                [$siteName],
                $customPrompt
            );
            return $this->generateWithAI($prompt, 'Contact');
        }

        $prompt = <<<PROMPT
Create a "Contact Us" page for a Thai website.

SITE NAME: {$siteName}

REQUIREMENTS:
1. Write in Thai language
2. Friendly, welcoming tone
3. Include sections for:
   - Welcome message
   - Contact form placeholder
   - Email contact (use placeholder: contact@{domain})
   - Response time expectations
   - FAQ before contacting
   - Business hours (general)
4. 300-500 words
5. Proper HTML structure
6. Include placeholders for contact form

OUTPUT: HTML content only, no markdown.
PROMPT;

        return $this->generateWithAI($prompt, 'Contact');
    }

    /**
     * Generate Editorial Policy page
     */
    private function generateEditorialPolicyPage(array $site): array {
        $siteName = $site['name'] ?? 'Our Website';
        $topic = $site['main_topic'] ?? 'entertainment';

        $customPrompt = getPromptTemplate('eeat_editorial', '');
        if ($customPrompt) {
            $prompt = str_replace(
                ['{site_name}', '{topic}'],
                [$siteName, $topic],
                $customPrompt
            );
            return $this->generateWithAI($prompt, 'Editorial Policy');
        }

        $prompt = <<<PROMPT
Create an "Editorial Policy" page for a Thai website about gaming/entertainment information.

SITE NAME: {$siteName}
TOPIC: {$topic}

REQUIREMENTS:
1. Write in Thai language
2. Professional, authoritative tone
3. Build EEAT (Experience, Expertise, Authoritativeness, Trustworthiness)
4. Cover:
   - Editorial mission and values
   - Content creation process
   - Research and fact-checking standards
   - Expert review process
   - Update and correction policy
   - Affiliate disclosure (if applicable)
   - Independence and objectivity
   - Author credentials (general)
   - Content review frequency
5. 600-900 words
6. Proper HTML structure

This page should make the site appear professional and trustworthy.

OUTPUT: HTML content only, no markdown.
PROMPT;

        return $this->generateWithAI($prompt, 'Editorial Policy');
    }

    /**
     * Generate content with AI
     */
    private function generateWithAI(string $prompt, string $pageType): array {
        $result = $this->ai->execute('eeat_content', $prompt, ['max_tokens' => 6000]);

        if (empty($result['content'])) {
            return ['success' => false, 'message' => 'AI failed to generate content'];
        }

        // Clean content
        $content = $result['content'];
        $content = preg_replace('/^```html\s*/im', '', $content);
        $content = preg_replace('/^```\s*/m', '', $content);
        $content = trim($content);

        // Extract title if present
        $title = $pageType;
        if (preg_match('/<h1[^>]*>(.+?)<\/h1>/is', $content, $matches)) {
            $title = strip_tags(trim($matches[1]));
        }

        return [
            'success' => true,
            'page_type' => $pageType,
            'title' => $title,
            'content' => $content,
            'word_count' => str_word_count(strip_tags($content))
        ];
    }

    /**
     * Generate all essential pages for a site
     */
    public function generateAllPages(array $siteInfo): array {
        $pages = [
            self::PAGE_ABOUT,
            self::PAGE_TERMS,
            self::PAGE_PRIVACY,
            self::PAGE_EDITORIAL
        ];

        $results = [];
        foreach ($pages as $pageType) {
            $results[$pageType] = $this->generatePage($pageType, $siteInfo);
            // Delay between requests
            sleep(2);
        }

        return $results;
    }

    /**
     * Add author bio section to article
     */
    public function addAuthorBio(string $content, array $authorInfo = []): string {
        $authorName = $authorInfo['name'] ?? 'ทีมบรรณาธิการ';
        $authorRole = $authorInfo['role'] ?? 'นักเขียนและผู้เชี่ยวชาญด้านเนื้อหา';
        $authorBio = $authorInfo['bio'] ?? 'ทีมงานผู้เชี่ยวชาญที่มุ่งมั่นนำเสนอข้อมูลที่ถูกต้อง เป็นกลาง และเป็นประโยชน์ต่อผู้อ่าน';

        $bioSection = <<<HTML

<section class="author-bio" style="background:#f8f9fa;padding:20px;border-radius:8px;margin-top:30px;">
<h3>เกี่ยวกับผู้เขียน</h3>
<p><strong>{$authorName}</strong> - {$authorRole}</p>
<p>{$authorBio}</p>
<p><em>บทความนี้ผ่านการตรวจสอบความถูกต้องและอัปเดตล่าสุดเมื่อ {DATE}</em></p>
</section>

HTML;

        $bioSection = str_replace('{DATE}', date('d/m/Y'), $bioSection);

        // Add before closing tags or at end
        if (stripos($content, '</article>') !== false) {
            $content = str_ireplace('</article>', $bioSection . '</article>', $content);
        } else {
            $content .= $bioSection;
        }

        return $content;
    }

    /**
     * Add "Last Updated" notice to content
     */
    public function addLastUpdated(string $content, string $date = null): string {
        $date = $date ?? date('d F Y');

        $notice = "<p class=\"last-updated\"><em>อัปเดตล่าสุด: {$date}</em></p>\n\n";

        // Add after first H1 or at beginning
        if (preg_match('/(<\/h1>)/i', $content)) {
            $content = preg_replace('/(<\/h1>)/i', '$1' . "\n" . $notice, $content, 1);
        } else {
            $content = $notice . $content;
        }

        return $content;
    }
}

/**
 * Helper function
 */
function eeatBuilder(): EEATBuilder {
    return new EEATBuilder();
}
