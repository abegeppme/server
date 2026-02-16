<?php
/**
 * AI Controller
 * Site-data constrained assistant endpoints (FAQ + glossary + retrieval answering)
 */

require_once __DIR__ . '/BaseController.php';

class AIController extends BaseController {
    private $faqFile;
    private $glossaryFile;

    public function __construct() {
        parent::__construct();
        $this->faqFile = __DIR__ . '/../../../client/content/serviceProviders/index.mdx';
        $this->glossaryFile = __DIR__ . '/../../data/glossary.json';
    }

    public function index() {
        $this->sendError('Use /ai/faqs, /ai/glossary, /ai/ask, /ai/conversations', 404);
    }

    public function get($id) {
        switch ($id) {
            case 'faqs':
                $this->getFaqs();
                return;
            case 'glossary':
                $this->getGlossary();
                return;
            case 'conversations':
                $this->getConversations();
                return;
            default:
                $this->sendError('Resource not found', 404);
        }
    }

    public function create() {
        $data = $this->getRequestBody();
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

        if (strpos($path, '/ai/ask') !== false) {
            $this->ask($data);
            return;
        }

        if (strpos($path, '/ai/conversations') !== false) {
            $this->createConversation();
            return;
        }

        $this->sendError('Unsupported AI action', 400);
    }

    protected function handleSubResource($method, $id, $sub_resource) {
        if ($method === 'get' && $id === 'glossary' && $sub_resource === 'search') {
            $this->searchGlossary();
            return;
        }

        if ($method === 'get' && $id === 'conversations') {
            $this->getConversation($sub_resource);
            return;
        }

        parent::handleSubResource($method, $id, $sub_resource);
    }

    private function getFaqs() {
        $query = strtolower(trim($_GET['q'] ?? ''));
        $faqs = $this->loadFaqs();

        if ($query !== '') {
            $faqs = array_values(array_filter($faqs, function ($item) use ($query) {
                $hay = strtolower(($item['question'] ?? '') . ' ' . ($item['answer'] ?? ''));
                return strpos($hay, $query) !== false;
            }));
        }

        $this->sendResponse($faqs);
    }

    private function getGlossary() {
        $term = strtolower(trim($_GET['term'] ?? ''));
        $glossary = $this->loadGlossary();

        if ($term !== '') {
            $glossary = array_values(array_filter($glossary, function ($item) use ($term) {
                return strpos(strtolower($item['term'] ?? ''), $term) !== false;
            }));
        }

        $this->sendResponse($glossary);
    }

    private function searchGlossary() {
        $q = strtolower(trim($_GET['q'] ?? ''));
        $glossary = $this->loadGlossary();

        if ($q !== '') {
            $glossary = array_values(array_filter($glossary, function ($item) use ($q) {
                $hay = strtolower(($item['term'] ?? '') . ' ' . ($item['definition'] ?? ''));
                return strpos($hay, $q) !== false;
            }));
        }

        $this->sendResponse($glossary);
    }

    private function ask(array $data) {
        $question = trim($data['question'] ?? '');
        if ($question === '') {
            $this->sendError('Question is required', 400);
        }

        $matches = $this->rankAnswers($question);
        if (count($matches) === 0) {
            $this->sendResponse([
                'answer' => 'I could not find this answer in the current site knowledge base yet. Please check FAQ/Glossary or contact support.',
                'sources' => ['site-content'],
            ]);
        }

        $top = $matches[0];
        $this->sendResponse([
            'answer' => $top['answer'],
            'sources' => [$top['source']],
        ]);
    }

    private function createConversation() {
        $conversation = [
            'id' => $this->generateUUID(),
            'messages' => [],
        ];
        $this->sendResponse($conversation, 201);
    }

    private function getConversations() {
        // Temporary stateless implementation; can be swapped with DB later.
        $this->sendResponse([]);
    }

    private function getConversation($id) {
        $this->sendResponse([
            'id' => $id,
            'messages' => [],
        ]);
    }

    private function loadFaqs(): array {
        if (!file_exists($this->faqFile)) {
            return [];
        }

        $content = file_get_contents($this->faqFile);
        if ($content === false) {
            return [];
        }

        $faqs = [];
        $pattern = '/-\s+question:\s*(.+?)\R\s+answer:\s*(.+?)(?=\R\s*-\s+question:|\R\R|\z)/s';
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $faqs[] = [
                    'question' => trim($m[1], " \t\n\r\0\x0B\"'"),
                    'answer' => trim($m[2], " \t\n\r\0\x0B\"'"),
                    'category' => 'service-providers',
                ];
            }
        }

        return $faqs;
    }

    private function loadGlossary(): array {
        if (!file_exists($this->glossaryFile)) {
            return [];
        }

        $raw = file_get_contents($this->glossaryFile);
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function rankAnswers(string $question): array {
        $questionTokens = $this->tokenize($question);
        $records = [];

        foreach ($this->loadFaqs() as $faq) {
            $combined = ($faq['question'] ?? '') . ' ' . ($faq['answer'] ?? '');
            $score = $this->scoreTokens($questionTokens, $this->tokenize($combined));
            if ($score > 0) {
                $records[] = [
                    'answer' => $faq['answer'],
                    'source' => 'faq:' . ($faq['question'] ?? 'site'),
                    'score' => $score,
                ];
            }
        }

        foreach ($this->loadGlossary() as $term) {
            $combined = ($term['term'] ?? '') . ' ' . ($term['definition'] ?? '');
            $score = $this->scoreTokens($questionTokens, $this->tokenize($combined));
            if ($score > 0) {
                $records[] = [
                    'answer' => ($term['term'] ?? 'Term') . ': ' . ($term['definition'] ?? ''),
                    'source' => 'glossary:' . ($term['term'] ?? 'site'),
                    'score' => $score,
                ];
            }
        }

        usort($records, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return $records;
    }

    private function tokenize(string $text): array {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
        $parts = preg_split('/\s+/', trim($text));
        if (!is_array($parts)) {
            return [];
        }

        $parts = array_filter($parts, function ($token) {
            return strlen($token) >= 3;
        });

        return array_values(array_unique($parts));
    }

    private function scoreTokens(array $queryTokens, array $candidateTokens): int {
        if (count($queryTokens) === 0 || count($candidateTokens) === 0) {
            return 0;
        }

        $candidateMap = array_flip($candidateTokens);
        $score = 0;
        foreach ($queryTokens as $token) {
            if (isset($candidateMap[$token])) {
                $score++;
            }
        }

        return $score;
    }
}
