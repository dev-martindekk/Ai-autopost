<?php
/**
 * AI AutoPost SEO System - Tone Controller
 * =========================================
 * ควบคุมโทนและลักษณะการเขียนของ AI
 *
 * Features:
 * - หลายโทนการเขียน (Professional, Review, Brand, Promo, etc.)
 * - ปรับระดับความเป็นทางการ
 * - ควบคุมความยาวประโยค
 * - เพิ่มความหลากหลายให้เนื้อหา
 * - ป้องกันบทความซ้ำกัน
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

class ToneController {

    // Available writing tones
    private const TONES = [
        'professional' => [
            'name' => 'มืออาชีพ',
            'description' => 'เขียนแบบผู้เชี่ยวชาญ น่าเชื่อถือ ใช้ศัพท์เฉพาะทาง',
            'characteristics' => [
                'formality' => 'high',
                'sentence_length' => 'medium',
                'vocabulary' => 'technical',
                'emotion' => 'neutral'
            ],
            'guidelines' => [
                'ใช้ภาษาที่ชัดเจน ตรงประเด็น',
                'อ้างอิงข้อมูลและสถิติ',
                'หลีกเลี่ยงคำสแลงหรือภาษาพูด',
                'ใช้ศัพท์เฉพาะทางเมื่อเหมาะสม',
                'เน้นความน่าเชื่อถือและความเป็นผู้เชี่ยวชาญ'
            ],
            'example_phrases' => [
                'จากการวิเคราะห์พบว่า',
                'ผู้เชี่ยวชาญแนะนำว่า',
                'ข้อมูลล่าสุดระบุว่า',
                'ตามหลักการที่ถูกต้อง'
            ]
        ],

        'review' => [
            'name' => 'รีวิว',
            'description' => 'เขียนแบบรีวิวสินค้า/บริการ ให้ข้อมูลทั้งข้อดีข้อเสีย',
            'characteristics' => [
                'formality' => 'medium',
                'sentence_length' => 'varied',
                'vocabulary' => 'accessible',
                'emotion' => 'balanced'
            ],
            'guidelines' => [
                'เล่าประสบการณ์ตรง',
                'ให้ทั้งข้อดีและข้อเสีย',
                'เปรียบเทียบกับตัวเลือกอื่น',
                'สรุปความคุ้มค่า',
                'แนะนำกลุ่มเป้าหมายที่เหมาะ'
            ],
            'example_phrases' => [
                'จากที่ได้ลองใช้มา',
                'ข้อดีที่เห็นได้ชัดคือ',
                'อย่างไรก็ตาม ยังมีจุดที่ต้องปรับปรุง',
                'โดยรวมแล้วให้คะแนน'
            ]
        ],

        'guide' => [
            'name' => 'คู่มือ',
            'description' => 'เขียนแบบ How-to สอนทีละขั้นตอน',
            'characteristics' => [
                'formality' => 'medium',
                'sentence_length' => 'short',
                'vocabulary' => 'simple',
                'emotion' => 'helpful'
            ],
            'guidelines' => [
                'แบ่งเป็นขั้นตอนชัดเจน',
                'ใช้ภาษาง่ายๆ',
                'มีตัวอย่างประกอบ',
                'เตือนข้อผิดพลาดที่พบบ่อย',
                'สรุปสิ่งสำคัญท้ายบท'
            ],
            'example_phrases' => [
                'ขั้นตอนที่ 1:',
                'สิ่งสำคัญที่ต้องทำคือ',
                'ข้อควรระวัง:',
                'เคล็ดลับ:'
            ]
        ],

        'casual' => [
            'name' => 'เป็นกันเอง',
            'description' => 'เขียนแบบพูดคุย เป็นมิตร เข้าถึงง่าย',
            'characteristics' => [
                'formality' => 'low',
                'sentence_length' => 'short',
                'vocabulary' => 'conversational',
                'emotion' => 'friendly'
            ],
            'guidelines' => [
                'ใช้ภาษาพูด',
                'เขียนเหมือนคุยกับเพื่อน',
                'ใส่อารมณ์และมุมมองส่วนตัว',
                'ใช้คำถามเพื่อดึงดูดผู้อ่าน',
                'เพิ่มความสนุกสนาน'
            ],
            'example_phrases' => [
                'รู้ไหมว่า',
                'พูดตรงๆ เลยนะ',
                'แอดเจอมาเองเลย',
                'บอกเลยว่าเจ๋งมาก'
            ]
        ],

        'promotional' => [
            'name' => 'โปรโมท',
            'description' => 'เขียนแบบโฆษณา กระตุ้นความสนใจ',
            'characteristics' => [
                'formality' => 'medium',
                'sentence_length' => 'short',
                'vocabulary' => 'persuasive',
                'emotion' => 'excited'
            ],
            'guidelines' => [
                'เน้นจุดเด่นและประโยชน์',
                'สร้างความเร่งด่วน',
                'ใช้ CTA ชัดเจน',
                'เน้นโปรโมชั่นพิเศษ',
                'กระตุ้นอารมณ์'
            ],
            'example_phrases' => [
                'พลาดไม่ได้!',
                'สมัครวันนี้รับทันที',
                'โปรโมชั่นพิเศษเฉพาะ',
                'รีบเลย! มีจำนวนจำกัด'
            ]
        ],

        'news' => [
            'name' => 'ข่าว',
            'description' => 'เขียนแบบรายงานข่าว ตรงประเด็น',
            'characteristics' => [
                'formality' => 'high',
                'sentence_length' => 'medium',
                'vocabulary' => 'formal',
                'emotion' => 'neutral'
            ],
            'guidelines' => [
                'นำเสนอข้อเท็จจริง',
                'ใช้โครงสร้างพีระมิดหัวกลับ',
                'ตอบ Who What When Where Why How',
                'หลีกเลี่ยงความเห็นส่วนตัว',
                'อ้างอิงแหล่งข่าว'
            ],
            'example_phrases' => [
                'ล่าสุด...',
                'มีรายงานว่า',
                'ตามแหล่งข่าวระบุว่า',
                'เป็นที่ทราบกันว่า'
            ]
        ],

        'storytelling' => [
            'name' => 'เล่าเรื่อง',
            'description' => 'เขียนแบบเล่าเรื่อง สร้างอารมณ์ร่วม',
            'characteristics' => [
                'formality' => 'low',
                'sentence_length' => 'varied',
                'vocabulary' => 'descriptive',
                'emotion' => 'engaging'
            ],
            'guidelines' => [
                'เริ่มด้วย hook ที่น่าสนใจ',
                'สร้างตัวละครหรือสถานการณ์',
                'มีจุดพลิก',
                'ให้บทเรียนหรือข้อคิด',
                'จบแบบน่าจดจำ'
            ],
            'example_phrases' => [
                'จะเล่าให้ฟัง...',
                'ตอนนั้นไม่มีใครคิดว่า',
                'แล้วสิ่งที่ไม่คาดคิดก็เกิดขึ้น',
                'บทเรียนที่ได้คือ'
            ]
        ],

        'expert' => [
            'name' => 'ผู้เชี่ยวชาญ',
            'description' => 'เขียนแบบอธิบายเชิงลึก วิชาการ',
            'characteristics' => [
                'formality' => 'very_high',
                'sentence_length' => 'long',
                'vocabulary' => 'academic',
                'emotion' => 'authoritative'
            ],
            'guidelines' => [
                'อธิบายหลักการและทฤษฎี',
                'ใช้ศัพท์เทคนิค',
                'อ้างอิงงานวิจัย',
                'วิเคราะห์เชิงลึก',
                'ให้มุมมองที่ไม่เหมือนใคร'
            ],
            'example_phrases' => [
                'จากการศึกษาพบว่า',
                'ตามทฤษฎี...',
                'ในแง่เทคนิค',
                'การวิเคราะห์เชิงลึกชี้ให้เห็นว่า'
            ]
        ]
    ];

    // Writing styles for variety
    private const STYLES = [
        'concise' => [
            'name' => 'กระชับ',
            'guidelines' => 'ใช้คำน้อย ตรงประเด็น ไม่อ้อมค้อม',
            'avg_sentence_words' => 12
        ],
        'detailed' => [
            'name' => 'ละเอียด',
            'guidelines' => 'อธิบายครบถ้วน มีตัวอย่าง ให้ข้อมูลเสริม',
            'avg_sentence_words' => 25
        ],
        'balanced' => [
            'name' => 'สมดุล',
            'guidelines' => 'ผสมผสานความกระชับและรายละเอียดอย่างเหมาะสม',
            'avg_sentence_words' => 18
        ]
    ];

    // Formality levels
    private const FORMALITY = [
        'formal' => [
            'name' => 'ทางการ',
            'pronouns' => ['ท่าน', 'ผู้อ่าน', 'ผู้ใช้งาน'],
            'avoid' => ['มาก', 'เจ๋ง', 'สุดๆ', 'โคตร']
        ],
        'semi_formal' => [
            'name' => 'กึ่งทางการ',
            'pronouns' => ['คุณ', 'ผู้เล่น', 'ผู้ใช้'],
            'avoid' => ['โคตร', 'ห่วย']
        ],
        'informal' => [
            'name' => 'ไม่เป็นทางการ',
            'pronouns' => ['คุณ', 'เพื่อนๆ', 'ทุกคน'],
            'avoid' => []
        ]
    ];

    private $selectedTone;
    private $selectedStyle;
    private $selectedFormality;
    private $customGuidelines;

    public function __construct() {
        $this->selectedTone = 'professional';
        $this->selectedStyle = 'balanced';
        $this->selectedFormality = 'semi_formal';
        $this->customGuidelines = [];
    }

    /**
     * Set the writing tone
     */
    public function setTone(string $tone): self {
        if (isset(self::TONES[$tone])) {
            $this->selectedTone = $tone;
        }
        return $this;
    }

    /**
     * Set the writing style
     */
    public function setStyle(string $style): self {
        if (isset(self::STYLES[$style])) {
            $this->selectedStyle = $style;
        }
        return $this;
    }

    /**
     * Set formality level
     */
    public function setFormality(string $formality): self {
        if (isset(self::FORMALITY[$formality])) {
            $this->selectedFormality = $formality;
        }
        return $this;
    }

    /**
     * Add custom guidelines
     */
    public function addGuidelines(array $guidelines): self {
        $this->customGuidelines = array_merge($this->customGuidelines, $guidelines);
        return $this;
    }

    /**
     * Get all available tones
     */
    public static function getAvailableTones(): array {
        $tones = [];
        foreach (self::TONES as $key => $tone) {
            $tones[$key] = [
                'name' => $tone['name'],
                'description' => $tone['description']
            ];
        }
        return $tones;
    }

    /**
     * Get all available styles
     */
    public static function getAvailableStyles(): array {
        return self::STYLES;
    }

    /**
     * Get all formality levels
     */
    public static function getFormalityLevels(): array {
        return self::FORMALITY;
    }

    /**
     * Generate tone prompt for AI
     */
    public function generateTonePrompt(): string {
        $tone = self::TONES[$this->selectedTone];
        $style = self::STYLES[$this->selectedStyle];
        $formality = self::FORMALITY[$this->selectedFormality];

        $prompt = "## แนวทางการเขียน\n\n";

        // Tone section
        $prompt .= "### โทนการเขียน: {$tone['name']}\n";
        $prompt .= "{$tone['description']}\n\n";
        $prompt .= "หลักการ:\n";
        foreach ($tone['guidelines'] as $guideline) {
            $prompt .= "- {$guideline}\n";
        }
        $prompt .= "\n";

        // Style section
        $prompt .= "### สไตล์: {$style['name']}\n";
        $prompt .= "{$style['guidelines']}\n";
        $prompt .= "ความยาวประโยคเฉลี่ย: ประมาณ {$style['avg_sentence_words']} คำต่อประโยค\n\n";

        // Formality section
        $prompt .= "### ระดับความเป็นทางการ: {$formality['name']}\n";
        $prompt .= "สรรพนามที่ใช้: " . implode(', ', $formality['pronouns']) . "\n";
        if (!empty($formality['avoid'])) {
            $prompt .= "หลีกเลี่ยงคำ: " . implode(', ', $formality['avoid']) . "\n";
        }
        $prompt .= "\n";

        // Example phrases
        if (!empty($tone['example_phrases'])) {
            $prompt .= "### ตัวอย่างวลีที่ใช้ได้:\n";
            foreach ($tone['example_phrases'] as $phrase) {
                $prompt .= "- \"{$phrase}\"\n";
            }
            $prompt .= "\n";
        }

        // Custom guidelines
        if (!empty($this->customGuidelines)) {
            $prompt .= "### แนวทางเพิ่มเติม:\n";
            foreach ($this->customGuidelines as $guideline) {
                $prompt .= "- {$guideline}\n";
            }
            $prompt .= "\n";
        }

        // Variety instruction
        $prompt .= "### สร้างความหลากหลาย:\n";
        $prompt .= "- เปลี่ยนโครงสร้างประโยคให้หลากหลาย\n";
        $prompt .= "- ใช้คำเชื่อมที่แตกต่างกัน\n";
        $prompt .= "- สลับความยาวประโยค (สั้น-กลาง-ยาว)\n";
        $prompt .= "- หลีกเลี่ยงการเริ่มประโยคด้วยคำซ้ำๆ\n";

        return $prompt;
    }

    /**
     * Get tone config for a specific tone
     */
    public function getToneConfig(string $tone): ?array {
        return self::TONES[$tone] ?? null;
    }

    /**
     * Recommend tone based on content type
     */
    public function recommendTone(string $contentType, string $topic): array {
        $recommendations = [
            'review' => ['tone' => 'review', 'style' => 'detailed', 'formality' => 'semi_formal'],
            'guide' => ['tone' => 'guide', 'style' => 'detailed', 'formality' => 'semi_formal'],
            'tips' => ['tone' => 'casual', 'style' => 'concise', 'formality' => 'informal'],
            'news' => ['tone' => 'news', 'style' => 'balanced', 'formality' => 'formal'],
            'comparison' => ['tone' => 'professional', 'style' => 'detailed', 'formality' => 'semi_formal'],
            'promotional' => ['tone' => 'promotional', 'style' => 'concise', 'formality' => 'informal'],
            'educational' => ['tone' => 'expert', 'style' => 'detailed', 'formality' => 'formal']
        ];

        // Topic-based adjustments
        $topicAdjustments = [
            'review' => ['tone' => 'review', 'formality' => 'informal'],
            'guide' => ['tone' => 'guide', 'formality' => 'semi_formal'],
            'news' => ['tone' => 'news', 'formality' => 'formal'],
            'tutorial' => ['tone' => 'expert', 'formality' => 'semi_formal'],
        ];

        $base = $recommendations[$contentType] ?? [
            'tone' => 'professional',
            'style' => 'balanced',
            'formality' => 'semi_formal'
        ];

        // Apply topic adjustments
        if (isset($topicAdjustments[$topic])) {
            $base = array_merge($base, $topicAdjustments[$topic]);
        }

        return $base;
    }

    /**
     * Create variation prompt to avoid duplicate content
     */
    public function createVariationPrompt(int $variationIndex = 0): string {
        $variations = [
            [
                'opening' => 'เริ่มด้วยคำถามที่น่าสนใจ',
                'structure' => 'ใช้รายการ bullet points เป็นหลัก',
                'closing' => 'จบด้วยคำถามให้ผู้อ่านคิดต่อ'
            ],
            [
                'opening' => 'เริ่มด้วยสถิติหรือข้อเท็จจริง',
                'structure' => 'ใช้ย่อหน้าอธิบายเป็นหลัก',
                'closing' => 'จบด้วยสรุปประเด็นสำคัญ'
            ],
            [
                'opening' => 'เริ่มด้วยเรื่องเล่าหรือสถานการณ์',
                'structure' => 'ผสมผสาน bullet และย่อหน้า',
                'closing' => 'จบด้วย CTA ชัดเจน'
            ],
            [
                'opening' => 'เริ่มด้วยปัญหาที่ผู้อ่านเจอ',
                'structure' => 'ใช้ขั้นตอน step-by-step',
                'closing' => 'จบด้วยบทเรียนหรือข้อคิด'
            ],
            [
                'opening' => 'เริ่มด้วยการเปรียบเทียบ',
                'structure' => 'ใช้ตาราง/การเปรียบเทียบ',
                'closing' => 'จบด้วยคำแนะนำส่วนตัว'
            ]
        ];

        $index = $variationIndex % count($variations);
        $variation = $variations[$index];

        return "### รูปแบบการเขียนครั้งนี้ (Variation #{$index}):\n" .
               "- การเปิด: {$variation['opening']}\n" .
               "- โครงสร้าง: {$variation['structure']}\n" .
               "- การปิด: {$variation['closing']}\n";
    }

    /**
     * Build complete writing prompt
     */
    public function buildCompletePrompt(array $options = []): string {
        $prompt = $this->generateTonePrompt();

        // Add variation if specified
        if (isset($options['variation_index'])) {
            $prompt .= "\n" . $this->createVariationPrompt($options['variation_index']);
        }

        // Add content type specific guidelines
        if (isset($options['content_type'])) {
            $prompt .= "\n### เนื้อหาประเภท: {$options['content_type']}\n";
        }

        // Add target audience
        if (isset($options['target_audience'])) {
            $prompt .= "### กลุ่มเป้าหมาย: {$options['target_audience']}\n";
        }

        return $prompt;
    }

    /**
     * Get current settings
     */
    public function getSettings(): array {
        return [
            'tone' => $this->selectedTone,
            'style' => $this->selectedStyle,
            'formality' => $this->selectedFormality,
            'tone_details' => self::TONES[$this->selectedTone],
            'style_details' => self::STYLES[$this->selectedStyle],
            'formality_details' => self::FORMALITY[$this->selectedFormality]
        ];
    }

    /**
     * Create from preset (for quick setup)
     */
    public static function fromPreset(string $preset): self {
        $controller = new self();

        $presets = [
            'product_review' => ['tone' => 'review', 'style' => 'detailed', 'formality' => 'informal'],
            'how_to_guide' => ['tone' => 'guide', 'style' => 'detailed', 'formality' => 'semi_formal'],
            'news_article' => ['tone' => 'news', 'style' => 'balanced', 'formality' => 'formal'],
            'promo_content' => ['tone' => 'promotional', 'style' => 'concise', 'formality' => 'informal'],
            'expert_guide' => ['tone' => 'expert', 'style' => 'detailed', 'formality' => 'formal'],
            'casual_tips' => ['tone' => 'casual', 'style' => 'concise', 'formality' => 'informal']
        ];

        if (isset($presets[$preset])) {
            $controller->setTone($presets[$preset]['tone'])
                       ->setStyle($presets[$preset]['style'])
                       ->setFormality($presets[$preset]['formality']);
        }

        return $controller;
    }
}

/**
 * Helper function
 */
function toneController(): ToneController {
    return new ToneController();
}
