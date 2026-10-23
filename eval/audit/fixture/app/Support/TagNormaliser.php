<?php

namespace App\Support;

class TagNormaliser
{
    /**
     * @param  list<string>  $tags
     * @return list<string>
     */
    public function unique(array $tags): array
    {
        $seen = [];

        foreach ($tags as $tag) {
            $tag = trim($tag);

            if ($tag !== '' && ! in_array($tag, $seen, true)) {
                $seen[] = $tag;
            }
        }

        return $seen;
    }
}
