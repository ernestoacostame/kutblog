<?php
// KutBlog - Taxonomy Manager (Categories & Tags)

function cms_taxonomies_file(): string {
    return CONTENT_PATH . '/taxonomies.json';
}

function cms_taxonomies_load(): array {
    $file = cms_taxonomies_file();
    if (!file_exists($file)) {
        return [
            'categories' => [],
            'tags'       => []
        ];
    }
    $raw = @file_get_contents($file);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [
            'categories' => [],
            'tags'       => []
        ];
    }
    if (!isset($data['categories']) || !is_array($data['categories'])) $data['categories'] = [];
    if (!isset($data['tags']) || !is_array($data['tags'])) $data['tags'] = [];
    return $data;
}

function cms_taxonomies_save_file(array $data): bool {
    $file = cms_taxonomies_file();
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return (bool)@file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Obtiene todas las categorías o etiquetas, combinando las registradas
 * en taxonomies.json con las utilizadas en artículos y páginas existentes.
 *
 * @param string $taxonomy 'categories' o 'tags'
 * @return array Lista de taxonomías con name, slug, description, count, is_custom
 */
function cms_taxonomies_get(string $taxonomy = 'categories'): array {
    $validTypes = ['categories', 'tags'];
    if (!in_array($taxonomy, $validTypes, true)) {
        $taxonomy = 'categories';
    }

    $registry = cms_taxonomies_load();
    $registeredItems = $registry[$taxonomy] ?? [];

    // Mapear registrados por clave normalizada (minúsculas)
    $mapped = [];
    foreach ($registeredItems as $item) {
        $name = trim($item['name'] ?? '');
        if ($name === '') continue;
        $key = mb_strtolower($name, 'UTF-8');
        $mapped[$key] = [
            'name'        => $name,
            'slug'        => !empty($item['slug']) ? $item['slug'] : slug_from_title($name),
            'description' => $item['description'] ?? '',
            'count'       => 0,
            'is_custom'   => true
        ];
    }

    // Escanear artículos y páginas para contar usos y descubrir taxonomías no registradas
    $contentTypes = ['articles', 'pages'];
    foreach ($contentTypes as $ctype) {
        $items = get_content_index($ctype);
        foreach ($items as $post) {
            $postTaxonomies = $post[$taxonomy] ?? [];
            if (!is_array($postTaxonomies)) continue;

            foreach ($postTaxonomies as $taxName) {
                $taxName = trim((string)$taxName);
                if ($taxName === '') continue;
                $key = mb_strtolower($taxName, 'UTF-8');

                if (isset($mapped[$key])) {
                    $mapped[$key]['count']++;
                } else {
                    $mapped[$key] = [
                        'name'        => $taxName,
                        'slug'        => slug_from_title($taxName),
                        'description' => '',
                        'count'       => 1,
                        'is_custom'   => false
                    ];
                }
            }
        }
    }

    // Ordenar alfabéticamente por nombre de manera insensible a mayúsculas
    $result = array_values($mapped);
    usort($result, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    return $result;
}

/**
 * Guarda o actualiza una categoría o etiqueta.
 * Si se especifica $original_name y es diferente a $name, renombra la taxonomía
 * en todos los artículos y páginas existentes.
 */
function cms_taxonomy_save(string $taxonomy, string $name, string $slug = '', string $description = '', ?string $original_name = null): array {
    $validTypes = ['categories', 'tags'];
    if (!in_array($taxonomy, $validTypes, true)) {
        return ['error' => 'Tipo de taxonomía inválido.'];
    }

    $name = trim($name);
    if ($name === '') {
        return ['error' => 'El nombre no puede estar vacío.'];
    }

    $slug = trim($slug);
    if ($slug === '') {
        $slug = slug_from_title($name);
    } else {
        $slug = slug_from_title($slug);
    }

    $description = trim($description);
    $registry = cms_taxonomies_load();

    $original_name = $original_name !== null ? trim($original_name) : null;
    $renamedCount = 0;

    // Si es un renombrado (el nombre original era distinto)
    if ($original_name !== null && $original_name !== '' && strcasecmp($original_name, $name) !== 0) {
        $contentTypes = ['articles', 'pages'];
        foreach ($contentTypes as $ctype) {
            $dir = content_path($ctype);
            if (!is_dir($dir)) continue;

            $files = glob("$dir/*.json");
            if (!$files) continue;

            foreach ($files as $file) {
                $raw = @file_get_contents($file);
                if (!$raw) continue;
                $post = json_decode($raw, true);
                if (!$post || !isset($post[$taxonomy]) || !is_array($post[$taxonomy])) continue;

                $modified = false;
                $newTaxonomies = [];
                foreach ($post[$taxonomy] as $existingName) {
                    if (strcasecmp((string)$existingName, $original_name) === 0) {
                        $newTaxonomies[] = $name;
                        $modified = true;
                    } else {
                        $newTaxonomies[] = $existingName;
                    }
                }

                if ($modified) {
                    // Eliminar duplicados manteniendo orden
                    $post[$taxonomy] = array_values(array_unique($newTaxonomies));
                    $post['updated_at'] = date('c');
                    @file_put_contents($file, json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    $renamedCount++;
                }
            }
        }

        // Eliminar el original del registro si existía
        $filtered = [];
        foreach ($registry[$taxonomy] as $item) {
            if (strcasecmp($item['name'] ?? '', $original_name) !== 0) {
                $filtered[] = $item;
            }
        }
        $registry[$taxonomy] = $filtered;
    }

    // Buscar si ya existe el nuevo nombre en el registro
    $found = false;
    foreach ($registry[$taxonomy] as &$item) {
        if (strcasecmp($item['name'] ?? '', $name) === 0) {
            $item['name']        = $name;
            $item['slug']        = $slug;
            $item['description'] = $description;
            $found = true;
            break;
        }
    }
    unset($item);

    if (!$found) {
        $registry[$taxonomy][] = [
            'name'        => $name,
            'slug'        => $slug,
            'description' => $description
        ];
    }

    cms_taxonomies_save_file($registry);

    if ($renamedCount > 0) {
        invalidate_content_index();
        clear_cache();
    }

    return [
        'success'       => true,
        'renamed_posts' => $renamedCount,
        'name'          => $name,
        'slug'          => $slug
    ];
}

/**
 * Elimina una categoría o etiqueta del registro y opcionalmente de todos los artículos/páginas.
 */
function cms_taxonomy_delete(string $taxonomy, string $name, bool $remove_from_posts = true): array {
    $validTypes = ['categories', 'tags'];
    if (!in_array($taxonomy, $validTypes, true)) {
        return ['error' => 'Tipo de taxonomía inválido.'];
    }

    $name = trim($name);
    if ($name === '') {
        return ['error' => 'Nombre no especificado.'];
    }

    $registry = cms_taxonomies_load();

    // Eliminar del registro
    $registry[$taxonomy] = array_values(array_filter($registry[$taxonomy], function($item) use ($name) {
        return strcasecmp($item['name'] ?? '', $name) !== 0;
    }));
    cms_taxonomies_save_file($registry);

    $affectedPosts = 0;
    if ($remove_from_posts) {
        $contentTypes = ['articles', 'pages'];
        foreach ($contentTypes as $ctype) {
            $dir = content_path($ctype);
            if (!is_dir($dir)) continue;

            $files = glob("$dir/*.json");
            if (!$files) continue;

            foreach ($files as $file) {
                $raw = @file_get_contents($file);
                if (!$raw) continue;
                $post = json_decode($raw, true);
                if (!$post || !isset($post[$taxonomy]) || !is_array($post[$taxonomy])) continue;

                $initialCount = count($post[$taxonomy]);
                $post[$taxonomy] = array_values(array_filter($post[$taxonomy], function($t) use ($name) {
                    return strcasecmp((string)$t, $name) !== 0;
                }));

                if (count($post[$taxonomy]) !== $initialCount) {
                    $post['updated_at'] = date('c');
                    @file_put_contents($file, json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    $affectedPosts++;
                }
            }
        }
    }

    invalidate_content_index();
    clear_cache();

    return [
        'success'        => true,
        'affected_posts' => $affectedPosts
    ];
}

/**
 * Fusiona una taxonomía en otra (reemplaza $source_name por $target_name en todos los posts).
 */
function cms_taxonomy_merge(string $taxonomy, string $source_name, string $target_name): array {
    $validTypes = ['categories', 'tags'];
    if (!in_array($taxonomy, $validTypes, true)) {
        return ['error' => 'Tipo de taxonomía inválido.'];
    }

    $source_name = trim($source_name);
    $target_name = trim($target_name);

    if ($source_name === '' || $target_name === '') {
        return ['error' => 'Debes indicar el término origen y el término destino.'];
    }

    if (strcasecmp($source_name, $target_name) === 0) {
        return ['error' => 'El término origen y destino son idénticos.'];
    }

    $registry = cms_taxonomies_load();

    // Eliminar $source_name de taxonomies.json
    $registry[$taxonomy] = array_values(array_filter($registry[$taxonomy], function($item) use ($source_name) {
        return strcasecmp($item['name'] ?? '', $source_name) !== 0;
    }));

    // Asegurar que $target_name esté en taxonomies.json si no lo está ya
    $hasTarget = false;
    foreach ($registry[$taxonomy] as $item) {
        if (strcasecmp($item['name'] ?? '', $target_name) === 0) {
            $hasTarget = true;
            break;
        }
    }
    if (!$hasTarget) {
        $registry[$taxonomy][] = [
            'name'        => $target_name,
            'slug'        => slug_from_title($target_name),
            'description' => ''
        ];
    }
    cms_taxonomies_save_file($registry);

    // Reemplazar en todos los artículos y páginas
    $affectedPosts = 0;
    $contentTypes = ['articles', 'pages'];
    foreach ($contentTypes as $ctype) {
        $dir = content_path($ctype);
        if (!is_dir($dir)) continue;

        $files = glob("$dir/*.json");
        if (!$files) continue;

        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            if (!$raw) continue;
            $post = json_decode($raw, true);
            if (!$post || !isset($post[$taxonomy]) || !is_array($post[$taxonomy])) continue;

            $modified = false;
            $updatedList = [];
            foreach ($post[$taxonomy] as $tName) {
                if (strcasecmp((string)$tName, $source_name) === 0) {
                    $updatedList[] = $target_name;
                    $modified = true;
                } else {
                    $updatedList[] = $tName;
                }
            }

            if ($modified) {
                $post[$taxonomy] = array_values(array_unique($updatedList));
                $post['updated_at'] = date('c');
                @file_put_contents($file, json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $affectedPosts++;
            }
        }
    }

    invalidate_content_index();
    clear_cache();

    return [
        'success'        => true,
        'affected_posts' => $affectedPosts,
        'target_name'    => $target_name
    ];
}
