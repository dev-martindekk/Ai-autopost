<?php
/**
 * AI AutoPost SEO System - AI Image Generator
 * ============================================
 * Generate images using OpenAI DALL-E API
 * รองรับการสร้างรูปที่สัมพันธ์กับ Title อัตโนมัติ
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

class ImageGenerator {
    private $apiKey;
    private $endpoint = 'https://api.openai.com/v1/images/generations';
    private $provider = 'openai'; // 'openai' or 'openrouter'
    private $useAIAnalysis = true; // ใช้ AI วิเคราะห์ title ก่อนสร้างรูป

    // ============================================
    // VARIATION ARRAYS - เพิ่มความหลากหลายให้รูป
    // ============================================

    // Art Styles - สไตล์ศิลปะหลากหลาย
    private $artStyles = [
        'photorealistic 3D render with ray tracing',
        'cinematic movie poster style',
        'vibrant digital illustration',
        'hyper-realistic CGI artwork',
        'dramatic concept art style',
        'glossy magazine advertisement style',
        'epic fantasy art illustration',
        'modern vector art with gradients',
        'luxurious product photography style',
        'dynamic action movie scene',
        'sleek futuristic design',
        'rich oil painting digital art',
        'neon cyberpunk aesthetic',
        'elegant art deco style',
        'bold pop art influence'
    ];

    // Camera Angles - มุมกล้องต่างๆ
    private $cameraAngles = [
        'dramatic low angle shot looking up',
        'bird\'s eye view from above',
        'dynamic dutch angle tilt',
        'intimate close-up detail shot',
        'wide establishing shot',
        'over-the-shoulder perspective',
        'symmetrical centered composition',
        'diagonal dynamic composition',
        'extreme close-up macro view',
        'panoramic wide angle view',
        'three-quarter angle view',
        'straight-on frontal view'
    ];

    // Lighting Styles - รูปแบบแสง
    private $lightingStyles = [
        'golden hour warm sunlight',
        'dramatic chiaroscuro lighting',
        'neon glow ambient lighting',
        'soft diffused studio lighting',
        'high contrast rim lighting',
        'colorful RGB gaming lights',
        'mystical ethereal glow',
        'cinematic spotlight dramatic',
        'natural daylight bright',
        'moody low-key shadows',
        'vibrant multi-colored lights',
        'elegant backlit silhouette',
        'warm candlelight ambiance',
        'cool moonlight blue tones',
        'fiery orange and red glow'
    ];

    // Background Environments - ฉากหลัง
    private $backgrounds = [
        'luxurious VIP lounge interior',
        'futuristic neon cityscape',
        'abstract geometric patterns',
        'cosmic space with stars',
        'elegant marble and gold room',
        'high-tech digital environment',
        'misty atmospheric fog',
        'explosion of colorful particles',
        'sleek minimalist backdrop',
        'dramatic storm clouds',
        'underwater light rays',
        'crystal cave reflections',
        'aurora borealis sky',
        'volcanic lava glow',
        'tropical paradise sunset'
    ];

    // Visual Effects - เอฟเฟกต์พิเศษ
    private $visualEffects = [
        'sparkles and glitter particles',
        'motion blur speed lines',
        'lens flare and light streaks',
        'floating magical particles',
        'explosion of confetti',
        'swirling energy vortex',
        'water splash dynamics',
        'fire and ember particles',
        'electric lightning bolts',
        'holographic rainbow effect',
        'smoke and mist trails',
        'shattered glass fragments',
        'golden dust particles',
        'bubble and orb reflections',
        'light beam rays'
    ];

    // Color Schemes - ชุดสี
    private $colorSchemes = [
        'rich gold and deep purple royal',
        'neon pink and electric blue cyber',
        'emerald green and gold luxury',
        'fiery red and orange hot',
        'cool blue and silver frost',
        'black and gold premium',
        'rainbow spectrum vibrant',
        'rose gold and cream elegant',
        'crimson red and black dramatic',
        'teal and coral tropical',
        'purple and gold majestic',
        'white and gold pristine',
        'navy and bronze classic',
        'lime and black energetic',
        'sunset orange and pink warm'
    ];

    public function __construct() {
        $this->loadApiKey();
    }

    /**
     * Load API key from database
     * Priority: OpenAI direct → OpenRouter (ใช้ DALL-E ผ่าน OpenRouter)
     */
    private function loadApiKey(): void {
        // 1. Try OpenAI direct
        $settings = db()->fetchOne("
            SELECT api_key FROM ai_settings
            WHERE provider_name = 'openai' AND is_enabled = 1
        ");

        if ($settings && !empty($settings['api_key'])) {
            $this->apiKey = decrypt($settings['api_key']);
            $this->provider = 'openai';
            $this->endpoint = 'https://api.openai.com/v1/images/generations';
            return;
        }

        // 2. Fallback to OpenRouter
        $settings = db()->fetchOne("
            SELECT api_key FROM ai_settings
            WHERE provider_name = 'openrouter' AND is_enabled = 1
        ");

        if ($settings && !empty($settings['api_key'])) {
            $this->apiKey = decrypt($settings['api_key']);
            $this->provider = 'openrouter';
            $this->endpoint = 'https://openrouter.ai/api/v1/images/generations';
            return;
        }

        throw new Exception('ไม่พบ API Key สำหรับสร้างรูปภาพ (OpenAI หรือ OpenRouter)');
    }

    /**
     * วิเคราะห์ Title ด้วย AI เพื่อสร้าง Image Prompt ที่ตรงประเด็น
     */
    private function analyzeTitle(string $title, string $keyword): array {
        // ดึงคำสำคัญจาก title
        $analysis = [
            'main_subject' => $this->extractMainSubject($title, $keyword),
            'action' => $this->extractAction($title),
            'emotion' => $this->extractEmotion($title),
            'numbers' => $this->extractNumbers($title),
            'brand' => $this->extractBrand($title),
            'type' => $this->detectContentType($title)
        ];

        return $analysis;
    }

    /**
     * ดึงหัวข้อหลักจาก title
     */
    private function extractMainSubject(string $title, string $keyword): string {
        // ใช้ keyword เป็นหัวข้อหลักถ้ามี
        if (!empty($keyword)) {
            return $keyword;
        }

        // ลบคำไม่จำเป็นออก
        $stopWords = ['วิธี', 'เคล็ดลับ', 'แนะนำ', 'รีวิว', 'ทดลอง', 'สมัคร', 'เล่น', 'ที่', 'และ', 'กับ', 'ใน', 'จาก', 'สำหรับ', 'ปี', '2024', '2025'];
        $cleaned = $title;
        foreach ($stopWords as $word) {
            $cleaned = str_replace($word, ' ', $cleaned);
        }

        // ดึงคำแรกที่เป็นคำหลัก
        $words = preg_split('/\s+/', trim($cleaned));
        foreach ($words as $word) {
            if (mb_strlen($word) >= 3) {
                return $word;
            }
        }

        return $keyword ?: $title;
    }

    /**
     * ดึง action/verb จาก title
     */
    private function extractAction(string $title): string {
        $actions = [
            'ทดลองเล่น' => 'trying out',
            'สมัคร' => 'signing up',
            'เล่น' => 'playing',
            'ชนะ' => 'winning',
            'รับโบนัส' => 'receiving bonus',
            'แจกเครดิต' => 'getting free credits',
            'ถอนเงิน' => 'withdrawing money',
            'ฝากเงิน' => 'depositing',
            'รีวิว' => 'reviewing',
            'เปรียบเทียบ' => 'comparing',
            'แนะนำ' => 'recommending',
            'เปิดตัว' => 'launching new'
        ];

        foreach ($actions as $thai => $eng) {
            if (mb_stripos($title, $thai) !== false) {
                return $eng;
            }
        }

        return 'showcasing';
    }

    /**
     * ดึงอารมณ์/บรรยากาศจาก title
     */
    private function extractEmotion(string $title): string {
        $emotions = [
            'แจกหนัก' => 'exciting celebration',
            'โบนัส' => 'rewarding moment',
            'ฟรี' => 'generous offer',
            'ใหม่ล่าสุด' => 'fresh and new',
            'ยอดนิยม' => 'popular trending',
            'อันดับ 1' => 'champion winner',
            'มาแรง' => 'hot trending',
            'แตกง่าย' => 'lucky winning',
            'แตกหนัก' => 'massive jackpot',
            'ปลอดภัย' => 'trusted secure',
            'น่าเชื่อถือ' => 'reliable trusted',
            'เว็บตรง' => 'authentic official'
        ];

        foreach ($emotions as $thai => $eng) {
            if (mb_stripos($title, $thai) !== false) {
                return $eng;
            }
        }

        return 'professional premium';
    }

    /**
     * ดึงตัวเลขจาก title (เช่น โบนัส 100%, ฝาก 50)
     */
    private function extractNumbers(string $title): array {
        $numbers = [];

        // หา pattern เงิน/โบนัส
        if (preg_match('/(\d+)\s*%/', $title, $matches)) {
            $numbers['percentage'] = $matches[1];
        }
        if (preg_match('/(\d+)\s*บาท/', $title, $matches)) {
            $numbers['amount'] = $matches[1];
        }
        if (preg_match('/(\d+)\s*เครดิต/', $title, $matches)) {
            $numbers['credits'] = $matches[1];
        }
        if (preg_match('/(\d+)\s*เท่า/', $title, $matches)) {
            $numbers['multiplier'] = $matches[1];
        }

        return $numbers;
    }

    /**
     * ดึงชื่อแบรนด์จาก title
     */
    private function extractBrand(string $title): string {
        // Pattern สำหรับชื่อเว็บ/แบรนด์ทั่วไป
        $patterns = [
            '/\b[a-zA-Z]+\d+\b/' => 'Platform'
        ];

        foreach ($patterns as $pattern => $brand) {
            if (preg_match($pattern, $title)) {
                return $brand;
            }
        }

        return '';
    }

    /**
     * ตรวจจับประเภทเนื้อหา
     */
    private function detectContentType(string $title): string {
        $types = [
            'ทดลองเล่น' => 'demo_play',
            'รีวิว' => 'review',
            'วิธี' => 'tutorial',
            'เคล็ดลับ' => 'tips',
            'สมัคร' => 'registration',
            'โปรโมชั่น' => 'promotion',
            'โบนัส' => 'bonus',
            'เปรียบเทียบ' => 'comparison',
            'อันดับ' => 'ranking',
            'แนะนำ' => 'recommendation',
            'ผล' => 'results'
        ];

        foreach ($types as $thai => $type) {
            if (mb_stripos($title, $thai) !== false) {
                return $type;
            }
        }

        return 'general';
    }

    /**
     * Generate image based on article title/keyword
     * ปรับปรุงให้วิเคราะห์ title และสร้างรูปที่ตรงประเด็น
     */
    public function generateForArticle(string $title, string $topic = 'general', string $keyword = ''): array {
        // วิเคราะห์ title เพื่อเข้าใจเนื้อหา
        $analysis = $this->analyzeTitle($title, $keyword);

        // Build prompt based on analysis
        $prompt = $this->buildSmartPrompt($title, $topic, $keyword, $analysis);

        return $this->generate($prompt);
    }

    /**
     * สร้าง Prompt อัจฉริยะจากการวิเคราะห์ Title
     * ปรับปรุงให้มีความหลากหลายสูงสุด - ไม่มีรูปไหนเหมือนกัน
     */
    private function buildSmartPrompt(string $title, string $topic, string $keyword, array $analysis): string {
        // ============================================
        // RANDOM VARIATION SELECTION - สุ่มทุกองค์ประกอบ
        // ============================================

        // สุ่มเลือก Art Style
        $artStyle = $this->getRandomFromArray($this->artStyles);

        // สุ่มเลือก Camera Angle
        $cameraAngle = $this->getRandomFromArray($this->cameraAngles);

        // สุ่มเลือก Lighting
        $lighting = $this->getRandomFromArray($this->lightingStyles);

        // สุ่มเลือก Background
        $background = $this->getRandomFromArray($this->backgrounds);

        // สุ่มเลือก Visual Effect
        $visualEffect = $this->getRandomFromArray($this->visualEffects);

        // สุ่มเลือก Color Scheme
        $colorScheme = $this->getRandomFromArray($this->colorSchemes);

        // Safety requirements
        $safety = "absolutely no text, no letters, no words, no numbers, no watermarks, no logos, no brand names visible";

        // สร้าง visual elements ตามประเภทเนื้อหา
        $contentTypeVisuals = $this->getContentTypeVisuals($analysis['type'], $topic);

        // สร้าง action visual ตาม action ที่พบ
        $actionVisual = $this->getActionVisual($analysis['action']);

        // สร้าง topic-specific elements (สุ่มใหม่ทุกครั้ง)
        $topicElements = $this->getTopicElements($topic);

        // สร้าง special elements ถ้ามีตัวเลข
        $specialElements = $this->getSpecialElements($analysis['numbers']);

        // Clean title และ keyword
        $cleanTitle = preg_replace('/[^\p{L}\p{N}\s]/u', '', $title);
        $cleanTitle = mb_substr($cleanTitle, 0, 50);

        $cleanKeyword = preg_replace('/[^\p{L}\p{N}\s]/u', '', $keyword ?: $analysis['main_subject']);
        $cleanKeyword = mb_substr($cleanKeyword, 0, 30);

        // สุ่มเลือก prompt structure (หลายรูปแบบ)
        $promptStructure = rand(1, 5);

        switch ($promptStructure) {
            case 1:
                // Structure 1: Art style first
                $prompt = "{$artStyle} of {$topicElements['elements']}. ";
                $prompt .= "Theme: {$cleanKeyword}. ";
                $prompt .= "Scene composition: {$cameraAngle}. ";
                $prompt .= "Lighting: {$lighting}. ";
                $prompt .= "Background: {$background}. ";
                $prompt .= "Effects: {$visualEffect}. ";
                $prompt .= "Color palette: {$colorScheme}. ";
                break;

            case 2:
                // Structure 2: Scene description first
                $prompt = "A stunning {$cameraAngle} capturing {$topicElements['elements']}. ";
                $prompt .= "Art style: {$artStyle}. ";
                $prompt .= "Subject: {$cleanKeyword} - {$contentTypeVisuals}. ";
                $prompt .= "Environment: {$background} with {$lighting}. ";
                $prompt .= "Visual effects: {$visualEffect}. ";
                $prompt .= "Colors: {$colorScheme}. ";
                break;

            case 3:
                // Structure 3: Mood/atmosphere focused
                $prompt = "An image with {$lighting} in {$artStyle}. ";
                $prompt .= "Main focus: {$topicElements['elements']} representing {$cleanKeyword}. ";
                $prompt .= "Shot from {$cameraAngle}. ";
                $prompt .= "Set against {$background}. ";
                $prompt .= "Enhanced with {$visualEffect}. ";
                $prompt .= "Mood: {$analysis['emotion']}, colors: {$colorScheme}. ";
                break;

            case 4:
                // Structure 4: Cinematic narrative
                $prompt = "Cinematic {$cameraAngle} scene: {$topicElements['elements']}. ";
                $prompt .= "Visual concept: {$cleanKeyword} - {$actionVisual}. ";
                $prompt .= "Rendered in {$artStyle}. ";
                $prompt .= "Atmosphere: {$background}, {$lighting}. ";
                $prompt .= "Special effects: {$visualEffect}. ";
                $prompt .= "Color grading: {$colorScheme}. ";
                break;

            case 5:
                // Structure 5: Technical description
                $prompt = "Professional {$artStyle}, {$cameraAngle}. ";
                $prompt .= "Subject matter: {$topicElements['elements']} for {$cleanKeyword}. ";
                $prompt .= "Visual treatment: {$contentTypeVisuals}. ";
                $prompt .= "Lighting setup: {$lighting}. ";
                $prompt .= "Background design: {$background}. ";
                $prompt .= "Post effects: {$visualEffect}, {$colorScheme}. ";
                break;
        }

        // เพิ่ม special elements ถ้ามี
        if (!empty($specialElements)) {
            $prompt .= "Emphasize: {$specialElements}. ";
        }

        // เพิ่ม mood จาก topic
        $prompt .= "Overall mood: {$topicElements['mood']}. ";

        // Safety และ quality
        $prompt .= "Technical: HD quality, detailed, professional. ";
        $prompt .= "IMPORTANT: {$safety}. ";

        // Unique identifier - ใช้ทั้ง time และ random
        $uniqueId = date('YmdHis') . '_' . bin2hex(random_bytes(4));
        $prompt .= "Unique variation seed: {$uniqueId}";

        return $prompt;
    }

    /**
     * Helper function สุ่มเลือกจาก array
     */
    private function getRandomFromArray(array $array): string {
        return $array[array_rand($array)];
    }

    /**
     * ดึง visual ตามประเภทเนื้อหา - มีหลาย options สุ่มเลือก
     */
    private function getContentTypeVisuals(string $type, string $topic): string {
        $visuals = [
            'demo_play' => [
                'interactive demo screen with glowing play button',
                'hands reaching towards floating game interface',
                'virtual reality gaming experience visualization',
                'trial mode activation with sparkle effects',
                'game preview portal opening dramatically',
                'finger about to tap glowing start button'
            ],
            'review' => [
                'magnifying glass examining premium product',
                'expert analysis with floating data points',
                'five star rating emerging from golden light',
                'professional evaluation scene with spotlight',
                'quality inspection with approval stamp',
                'detailed examination with holographic display'
            ],
            'tutorial' => [
                'glowing pathway showing step-by-step progress',
                'floating instruction cards in sequence',
                'knowledge transfer beam of light',
                'guide book opening with magical pages',
                'learning journey visualization with milestones',
                'mentor hand pointing to glowing steps'
            ],
            'tips' => [
                'golden lightbulb bursting with ideas',
                'treasure map revealing secret strategies',
                'key unlocking hidden knowledge vault',
                'whispered secrets with mystical aura',
                'brain with lightning bolt insights',
                'scroll unrolling ancient wisdom'
            ],
            'registration' => [
                'grand door opening to opportunity',
                'VIP welcome carpet rolling out',
                'golden key unlocking new world',
                'membership card glowing with power',
                'portal to exclusive realm opening',
                'celebration arch welcoming new member'
            ],
            'promotion' => [
                'gift box exploding with rewards',
                'special deal banner with fireworks',
                'exclusive offer spotlight on stage',
                'treasure chest overflowing with bonuses',
                'celebration confetti with prize reveal',
                'limited time hourglass with golden sand'
            ],
            'bonus' => [
                'cascade of golden coins and gems',
                'treasure vault door swinging open',
                'reward shower from above',
                'bonus multiplier explosion effect',
                'gift packages floating in celebration',
                'jackpot celebration with confetti burst'
            ],
            'comparison' => [
                'two options on glowing scales',
                'spotlight choosing the better path',
                'fork in road with clear winner',
                'podium showing ranked choices',
                'magnifying glass comparing details',
                'checklist with golden checkmarks'
            ],
            'ranking' => [
                'golden trophy on winner podium',
                'number one medal shining bright',
                'championship crown with jewels',
                'leaderboard with top position glowing',
                'victory pedestal with spotlight',
                'ranking chart with star at top'
            ],
            'recommendation' => [
                'expert hand presenting top choice',
                'golden seal of approval badge',
                'spotlight on recommended item',
                'thumbs up with golden glow',
                'award ribbon on premium selection',
                'certified excellence stamp'
            ],
            'results' => [
                'data results displayed on clean dashboard',
                'analytical report with clear visualizations',
                'performance metrics on elegant display',
                'achievement summary with positive indicators',
                'outcome chart with clear data points',
                'success metrics visualization'
            ],
            'general' => [
                'premium showcase with elegant presentation',
                'professional display with luxury feel',
                'high-end product spotlight',
                'elegant feature presentation',
                'sophisticated display arrangement',
                'classy exhibition setup'
            ]
        ];

        $options = $visuals[$type] ?? $visuals['general'];
        return $this->getRandomFromArray($options);
    }

    /**
     * ดึง visual ตาม action - มีหลาย options สุ่มเลือก
     */
    private function getActionVisual(string $action): string {
        $actionVisuals = [
            'trying out' => [
                'first experience discovery with wonder',
                'curious exploration of new territory',
                'test drive moment with excitement',
                'sampling premium experience firsthand',
                'beginner\'s lucky first attempt',
                'trial run with promising results'
            ],
            'signing up' => [
                'VIP membership welcome ceremony',
                'new journey beginning with fanfare',
                'exclusive club entry moment',
                'golden ticket acquisition scene',
                'registration completion celebration',
                'joining elite community event'
            ],
            'playing' => [
                'immersive gaming action in motion',
                'thrilling gameplay intensity',
                'active engagement with excitement',
                'dynamic gaming session highlight',
                'player in the zone moment',
                'gaming excitement at peak'
            ],
            'winning' => [
                'triumphant victory celebration',
                'jackpot moment with euphoria',
                'champion achievement unlocked',
                'success celebration explosion',
                'winning streak continuation',
                'ultimate victory achievement'
            ],
            'receiving bonus' => [
                'bonus collection with joy',
                'reward acceptance ceremony',
                'gift receiving moment of delight',
                'bonus shower from above',
                'reward activation success',
                'bonus claim celebration'
            ],
            'getting free credits' => [
                'free credits raining down',
                'complimentary rewards arrival',
                'credit boost activation',
                'free gift acceptance joy',
                'bonus credits flooding in',
                'complimentary prize collection'
            ],
            'withdrawing money' => [
                'successful cash out moment',
                'withdrawal completion satisfaction',
                'money transfer success',
                'payout arrival celebration',
                'funds secured confirmation',
                'withdrawal approval victory'
            ],
            'depositing' => [
                'secure investment moment',
                'funds addition with confidence',
                'deposit confirmation success',
                'balance boost activation',
                'account funding completion',
                'investment placement secured'
            ],
            'reviewing' => [
                'expert analysis in progress',
                'detailed examination underway',
                'professional evaluation scene',
                'comprehensive review process',
                'thorough inspection moment',
                'quality assessment in action'
            ],
            'comparing' => [
                'side by side evaluation',
                'options analysis in progress',
                'comparison decision moment',
                'choice weighing visualization',
                'alternatives examination scene',
                'best option determination'
            ],
            'recommending' => [
                'expert endorsement moment',
                'top pick selection reveal',
                'professional recommendation scene',
                'best choice presentation',
                'trusted suggestion delivery',
                'expert approval given'
            ],
            'launching new' => [
                'grand opening spectacular',
                'new release reveal event',
                'debut launch celebration',
                'fresh start announcement',
                'premiere showcase moment',
                'launch event excitement'
            ],
            'showcasing' => [
                'premium feature spotlight',
                'elegant presentation display',
                'highlight reel moment',
                'feature demonstration scene',
                'showcase exhibition view',
                'premium display arrangement'
            ]
        ];

        $options = $actionVisuals[$action] ?? $actionVisuals['showcasing'];
        return $this->getRandomFromArray($options);
    }

    /**
     * ดึง elements ตาม topic - เพิ่ม options มากขึ้นเพื่อความหลากหลาย
     */
    private function getTopicElements(string $topic): array {
        $elements = [
            'technology' => [
                'elements' => [
                    'glowing circuit board with flowing data streams',
                    'futuristic AI interface with neural network visualization',
                    'smartphone with holographic interface floating above',
                    'cloud computing visualization with connected nodes',
                    'developer typing code with multiple monitors glowing',
                    'robot hand and human hand touching fingertips',
                    'cybersecurity shield with digital protection layers',
                    'data center with rows of illuminated servers',
                    'augmented reality glasses projecting information',
                    'quantum computing processor visualization'
                ],
                'mood' => [
                    'innovative forward-thinking digital energy',
                    'precision and efficiency in modern tech',
                    'cutting-edge breakthrough atmosphere',
                    'connected digital world optimism',
                    'futuristic possibilities unfolding',
                    'technical mastery with elegant complexity'
                ],
                'colors' => [
                    'electric blue, cyan, silver with dark background',
                    'neon green matrix, deep black, white',
                    'purple gradient, electric blue, white glow',
                    'orange, deep blue, white light streaks',
                    'aqua teal, midnight, gold highlights',
                    'gradient violet, indigo, silver sheen'
                ]
            ],
            'business' => [
                'elements' => [
                    'modern office boardroom with city skyline view',
                    'business team collaboration around glowing table',
                    'upward trending graph on elegant presentation',
                    'handshake sealing deal with city backdrop',
                    'entrepreneur reviewing analytics on large screen',
                    'professional networking event in luxury venue',
                    'startup office with creative team working',
                    'financial growth chart with success trajectory',
                    'business strategy planning session overhead view',
                    'corporate tower reflecting golden sunset'
                ],
                'mood' => [
                    'professional success and achievement energy',
                    'collaborative team synergy atmosphere',
                    'ambition and growth mindset',
                    'strategic leadership confidence',
                    'prosperous business momentum',
                    'executive decision-making power'
                ],
                'colors' => [
                    'navy blue, gold, crisp white professional',
                    'charcoal, silver, accent orange modern',
                    'deep green, gold, cream elegance',
                    'slate gray, electric blue, white clarity',
                    'burgundy, brass, ivory sophistication',
                    'midnight blue, platinum, warm amber'
                ]
            ],
            'health' => [
                'elements' => [
                    'vibrant fruits and vegetables in colorful arrangement',
                    'person meditating in peaceful natural setting',
                    'athlete running at sunrise with energy trail',
                    'healthy meal preparation in bright modern kitchen',
                    'yoga pose against scenic nature backdrop',
                    'doctor consulting patient with caring expression',
                    'wellness spa with calming water elements',
                    'fitness workout with dynamic movement capture',
                    'green smoothie with fresh ingredients floating',
                    'serene nature walk through forest path'
                ],
                'mood' => [
                    'vibrant energy and vitality celebration',
                    'peaceful wellness and harmony',
                    'active lifestyle motivation',
                    'natural healing and balance',
                    'healthy living inspiration',
                    'mind-body connection serenity'
                ],
                'colors' => [
                    'fresh green, white, natural earth tones',
                    'sky blue, white, gentle yellow warmth',
                    'coral, mint, clean white freshness',
                    'deep forest green, gold, warm cream',
                    'lavender, soft white, sage green calm',
                    'energetic orange, lime green, white'
                ]
            ],
            'travel' => [
                'elements' => [
                    'breathtaking mountain landscape at golden hour',
                    'tropical beach with crystal clear turquoise water',
                    'ancient temple surrounded by jungle mist',
                    'bustling city street with colorful culture',
                    'airplane window view over clouds at sunset',
                    'passport and map on wooden travel desk',
                    'traditional market with vibrant local crafts',
                    'scenic train journey through countryside',
                    'luxury hotel infinity pool overlooking sea',
                    'adventure hiker at scenic overlook viewpoint'
                ],
                'mood' => [
                    'wanderlust adventure and exploration spirit',
                    'cultural discovery and wonder',
                    'peaceful escape and relaxation',
                    'exciting journey beginning',
                    'scenic beauty appreciation',
                    'authentic travel experience immersion'
                ],
                'colors' => [
                    'azure blue, sandy beige, golden sun',
                    'lush tropical green, ocean turquoise, white',
                    'earthy red, ochre, desert sand palette',
                    'cherry blossom pink, soft white, spring green',
                    'aurora purple, icy blue, snow white',
                    'sunset orange, warm pink, golden hour'
                ]
            ],
            'general' => [
                'elements' => [
                    'modern digital interface with flowing light streams',
                    'futuristic technology visualization with particles',
                    'premium abstract composition with dynamic shapes',
                    'sleek professional design with geometric elegance',
                    'innovative digital art with vibrant energy',
                    'holographic display with floating elements',
                    'minimalist luxury with golden accents',
                    'tech-forward design with neon highlights',
                    'organic flowing shapes with metallic finish',
                    'crystalline structures with light refraction',
                    'cloud-like ethereal formations',
                    'geometric patterns with depth layers',
                    'liquid metal flowing dynamically',
                    'particle explosion in slow motion',
                    'abstract waves of color and light'
                ],
                'mood' => [
                    'clean modern professional aesthetic',
                    'innovative forward-thinking energy',
                    'premium quality sophisticated feel',
                    'futuristic optimistic atmosphere',
                    'elegant simplicity with impact',
                    'dynamic motion with purpose'
                ],
                'colors' => [
                    'blue gradients, white, silver, modern purple',
                    'teal, coral, soft gold accents',
                    'midnight blue, electric cyan, white',
                    'gradient sunset, rose gold, cream',
                    'monochrome with single color accent',
                    'pastel rainbow, soft white, gentle gold'
                ]
            ]
        ];

        $topicData = $elements[$topic] ?? $elements['general'];

        return [
            'elements' => $this->getRandomFromArray($topicData['elements']),
            'mood' => $this->getRandomFromArray($topicData['mood']),
            'colors' => $this->getRandomFromArray($topicData['colors'])
        ];
    }

    /**
     * ดึง special elements จากตัวเลขที่พบใน title
     */
    private function getSpecialElements(array $numbers): string {
        $special = [];

        if (!empty($numbers['percentage'])) {
            $pct = $numbers['percentage'];
            if ($pct >= 100) {
                $special[] = "massive bonus celebration, doubling rewards visualization";
            } elseif ($pct >= 50) {
                $special[] = "significant bonus highlight, half-and-more reward concept";
            } else {
                $special[] = "bonus reward showcase";
            }
        }

        if (!empty($numbers['amount'])) {
            $amt = $numbers['amount'];
            if ($amt >= 10000) {
                $special[] = "big money jackpot visualization, large sum celebration";
            } elseif ($amt >= 1000) {
                $special[] = "significant winnings display";
            } else {
                $special[] = "accessible reward concept";
            }
        }

        if (!empty($numbers['multiplier'])) {
            $mult = $numbers['multiplier'];
            $special[] = "{$mult}x multiplier explosion effect, massive multiplication visualization";
        }

        if (!empty($numbers['credits'])) {
            $special[] = "free credits shower, credit bonus celebration";
        }

        return implode(', ', $special);
    }

    /**
     * Generate image using DALL-E (OpenAI direct or via OpenRouter)
     */
    public function generate(string $prompt, string $size = '1792x1024'): array {
        // DALL-E 3 supported sizes: 1024x1024, 1792x1024, 1024x1792
        // Using 1792x1024 as closest to requested 1254x836 (landscape)

        $data = [
            'model' => 'dall-e-3',
            'prompt' => $prompt,
            'n' => 1,
            'size' => $size,
            'quality' => 'standard',
            'style' => 'vivid'
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ];

        // OpenRouter requires additional headers
        if ($this->provider === 'openrouter') {
            $headers[] = 'HTTP-Referer: ' . (defined('BASE_URL') ? BASE_URL : 'http://localhost');
            $headers[] = 'X-Title: AI AutoPost SEO';
            // OpenRouter uses openai/dall-e-3 model name
            $data['model'] = 'openai/dall-e-3';
        }

        $ch = curl_init($this->endpoint);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120 // Image generation can take time
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'message' => "cURL Error: {$error}"];
        }

        $decoded = json_decode($response, true);

        if ($httpCode !== 200) {
            $errorMsg = $decoded['error']['message'] ?? 'Unknown error';
            $providerName = $this->provider === 'openrouter' ? 'OpenRouter/DALL-E' : 'DALL-E';
            return ['success' => false, 'message' => "{$providerName} Error: {$errorMsg}"];
        }

        if (empty($decoded['data'][0]['url'])) {
            return ['success' => false, 'message' => 'No image URL in response'];
        }

        $imageUrl = $decoded['data'][0]['url'];
        $revisedPrompt = $decoded['data'][0]['revised_prompt'] ?? $prompt;

        return [
            'success' => true,
            'image_url' => $imageUrl,
            'prompt' => $prompt,
            'revised_prompt' => $revisedPrompt,
            'provider' => $this->provider
        ];
    }

    /**
     * Download image and save locally
     */
    public function downloadImage(string $url, string $filename = null): array {
        $imageData = file_get_contents($url);

        if (!$imageData) {
            return ['success' => false, 'message' => 'Failed to download image'];
        }

        // Generate filename if not provided
        if (!$filename) {
            $filename = 'ai_' . date('Ymd_His') . '_' . uniqid() . '.png';
        }

        $uploadDir = UPLOADS_PATH . '/images/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filepath = $uploadDir . $filename;

        if (file_put_contents($filepath, $imageData)) {
            return [
                'success' => true,
                'filepath' => $filepath,
                'filename' => $filename,
                'size' => strlen($imageData)
            ];
        }

        return ['success' => false, 'message' => 'Failed to save image'];
    }

    /**
     * Generate and download image in one call
     * @param string $title Article title for prompt
     * @param string $topic Topic for styling
     * @param string $keyword Keyword for filename and alt text
     */
    public function generateAndDownload(string $title, string $topic = 'casino', string $keyword = ''): array {
        // Generate image with keyword for UNIQUE content-specific images
        $result = $this->generateForArticle($title, $topic, $keyword);

        if (!$result['success']) {
            return $result;
        }

        // Generate UNIQUE filename from keyword + timestamp
        $filename = null;
        if (!empty($keyword)) {
            // Create SEO-friendly filename with unique timestamp
            $cleanKeyword = $this->sanitizeFilename($keyword);
            $filename = $cleanKeyword . '-' . date('YmdHis') . '-' . substr(uniqid(), -4) . '.png';
        }

        // Download image
        $downloadResult = $this->downloadImage($result['image_url'], $filename);

        if (!$downloadResult['success']) {
            return $downloadResult;
        }

        return [
            'success' => true,
            'image_url' => $result['image_url'],
            'filepath' => $downloadResult['filepath'],
            'filename' => $downloadResult['filename'],
            'keyword' => $keyword,
            'prompt' => $result['prompt'],
            'revised_prompt' => $result['revised_prompt']
        ];
    }

    /**
     * Sanitize string for use as filename
     */
    private function sanitizeFilename(string $text): string {
        // Replace spaces with hyphens
        $text = preg_replace('/\s+/', '-', $text);
        // Remove special characters but keep Thai (including vowels/tone marks), alphanumeric, and hyphens
        // \p{L} = Letters, \p{M} = Marks (Thai vowels/tone marks), \p{N} = Numbers
        $text = preg_replace('/[^\p{L}\p{M}\p{N}\-]/u', '', $text);
        // Limit length
        $text = mb_substr($text, 0, 100);
        // Remove multiple hyphens
        $text = preg_replace('/-+/', '-', $text);
        return trim($text, '-');
    }

    /**
     * Generate alt text - ใช้แค่ keyword อย่างเดียวสำหรับ SEO
     */
    public function generateAltText(string $title, string $keyword, string $topic): string {
        // ใช้แค่ keyword อย่างเดียว ไม่ต้องเติมคำอื่น
        if (!empty($keyword)) {
            return trim($keyword);
        }

        // Fallback: ใช้ title ที่ clean แล้ว
        $cleanTitle = preg_replace('/[^\p{L}\p{N}\s]/u', '', $title);
        return mb_substr(trim($cleanTitle), 0, 125);
    }

    /**
     * Generate image caption
     */
    public function generateCaption(string $title, string $keyword): string {
        return "ภาพประกอบ: {$keyword}";
    }
}

/**
 * Helper function to get image generator instance
 */
function imageGenerator(): ImageGenerator {
    return new ImageGenerator();
}
