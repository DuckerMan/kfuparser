$parser = new Parser();

Получить токен: $token = $parser->auth('LOGIN', 'PASS);

Спарсить баллы: $stats = $parser->parse_menu_7('токен');

Спарсить информацию о студенте: $info = $parser->parse_student('токен');
