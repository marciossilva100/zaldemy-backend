<?php

/**
 * Lista de termos ofensivos por idioma, cobrindo os 15 idiomas suportados no front-end.
 * Não é exaustiva — cobre os termos mais comuns de cada idioma como uma primeira barreira.
 * Espelha src/utils/contentFilter.js do front-end.
 */
const BLOCKED_WORDS = [
    'pt' => [
        'arrombado', 'babaca', 'boiola', 'bosta', 'buceta', 'caralho', 'corno',
        'desgraçado', 'fdp', 'foda-se', 'fodase', 'gonorreia', 'krl', 'merda',
        'otario', 'otário', 'piranha', 'porra', 'puta', 'putaria', 'puto',
        'retardado', 'viado', 'xoxota',
    ],
    'en' => [
        'asshole', 'bastard', 'bitch', 'bullshit', 'cunt', 'faggot', 'fuck',
        'motherfucker', 'nigger', 'nigga', 'pussy', 'retard', 'shit', 'slut', 'whore',
    ],
    'es' => [
        'cabron', 'cabrón', 'coño', 'gilipollas', 'hijo de puta', 'hijueputa',
        'imbecil', 'imbécil', 'joder', 'maricon', 'maricón', 'mierda', 'pendejo',
        'pinche', 'puta', 'puto', 'verga', 'zorra',
    ],
    'fr' => [
        'batard', 'bâtard', 'connard', 'connasse', 'encule', 'enculé', 'foutre',
        'merde', 'nique ta mere', 'nique ta mère', 'pute', 'putain', 'salope',
    ],
    'de' => [
        'arschloch', 'fotze', 'hurensohn', 'missgeburt', 'mistkerl', 'scheisse',
        'scheiße', 'schlampe', 'spast', 'wichser',
    ],
    'it' => [
        'bastardo', 'cazzo', 'coglione', 'merda', 'puttana', 'stronzo', 'troia',
        'vaffanculo',
    ],
    'ru' => [
        'блять', 'бля', 'гандон', 'ебать', 'мудак', 'пизда', 'сука', 'тварь',
        'хуй', 'шлюха',
    ],
    'ja' => [
        '死ね', '馬鹿', 'ばか', 'くそ', 'ちくしょう', 'きちがい', 'まんこ', 'ちんこ',
        'ぶす', 'カス',
    ],
    'zh' => [
        '傻逼', '操', '妈的', '狗屎', '婊子', '贱人', '混蛋', '王八蛋', '白痴', '死全家',
    ],
    'ko' => [
        '씨발', '개새끼', '병신', '좆', '미친놈', '걸레', '쓰레기', '개자식', '지랄',
    ],
    'ar' => [
        'كلب', 'خرا', 'عاهرة', 'قحبة', 'شرموطة', 'منيك', 'ابن كلب', 'حقير',
    ],
    'tr' => [
        'amk', 'aptal', 'göt', 'ibne', 'kahpe', 'orospu', 'piç', 'salak',
        'siktir', 'yavşak',
    ],
    'pl' => [
        'chuj', 'cipa', 'dziwka', 'gówno', 'jebać', 'kurwa', 'pierdolić',
        'pizda', 'skurwysyn', 'spierdalaj',
    ],
    'nl' => [
        'eikel', 'godverdomme', 'hoer', 'kanker', 'klootzak', 'klote', 'kut',
        'lul', 'sukkel', 'tering',
    ],
    'hi' => [
        'चूतिया', 'गांडू', 'रंडी', 'कमीना', 'भोसड़ी', 'हरामी', 'हरामज़ादा',
    ],
];

// Idiomas sem separação por espaço entre palavras — busca por substring direta.
const SPACELESS_LANGUAGES = ['zh', 'ja'];

const DIACRITIC_MAP = [
    'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
    'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
    'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
    'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
    'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
    'ç' => 'c', 'ñ' => 'n', 'ş' => 's', 'ğ' => 'g', 'ı' => 'i', 'ő' => 'o', 'ű' => 'u',
    'ł' => 'l', 'ż' => 'z', 'ź' => 'z', 'ą' => 'a', 'ę' => 'e', 'ć' => 'c', 'ń' => 'n',
    'š' => 's', 'č' => 'c', 'ž' => 'z',
];

function zaldemyStripDiacritics(string $texto): string
{
    return strtr($texto, DIACRITIC_MAP);
}

function zaldemyNormalizarTexto(string $texto): string
{
    return mb_strtolower(zaldemyStripDiacritics($texto), 'UTF-8');
}

function zaldemyEscapeRegex(string $texto): string
{
    $escapado = preg_quote($texto, '/');
    return preg_replace('/\s+/', '\\s+', $escapado);
}

/**
 * Verifica se o texto contém algum termo da blocklist, em qualquer um dos 15 idiomas.
 * Usa limite de palavra baseado na propriedade Unicode \p{L}/\p{N} (funciona para
 * cirílico, árabe, hindi, coreano etc., diferente de \b que só reconhece [A-Za-z0-9_]).
 * Para chinês/japonês (sem espaço entre palavras) faz busca direta por substring.
 */
function verificarConteudoImproprio(string $texto): bool
{
    $texto = trim($texto);

    if ($texto === '') {
        return false;
    }

    $normalizado = zaldemyNormalizarTexto($texto);

    foreach (BLOCKED_WORDS as $idioma => $palavras) {
        $semEspaco = in_array($idioma, SPACELESS_LANGUAGES, true);

        foreach ($palavras as $palavra) {
            $escapado = zaldemyEscapeRegex(zaldemyNormalizarTexto($palavra));

            $pattern = $semEspaco
                ? "/{$escapado}/iu"
                : "/(?<![\\p{L}\\p{N}]){$escapado}(?![\\p{L}\\p{N}])/iu";

            if (preg_match($pattern, $normalizado)) {
                return true;
            }
        }
    }

    return false;
}
