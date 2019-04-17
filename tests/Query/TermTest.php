<?php
declare(strict_types=1);

namespace Query;

use PHPUnit\Framework\TestCase;

class TermTest extends TestCase
{
    public function testCreateWithSlashing()
    {
        $term = new \TypeRocket\Models\WPTerm();
        $term->setTaxonomy('category');
        $data = [
            'name' => 'new \'name\' \Name',
            'description' => 'Create \\ \TypeRocket\Name \'in quotes\'',
            'slug' => 'new-the-term-slug',
            'parent' => 0
        ];

        $term->create($data);

        wp_delete_term($term->getID(), $term->taxonomy);

        $name = $term->getProperty('name');
        $description = $term->getProperty('description');
        $slug = $term->getProperty('slug');
        $parent = $term->getProperty('parent');

        $this->assertTrue($name == $data['name']);
        $this->assertTrue($description == $data['description']);
        $this->assertTrue($slug == $data['slug']);
        $this->assertTrue($parent == $data['parent']);
    }

    public function testUpdateWithSlashing()
    {
        $term = new \TypeRocket\Models\WPTerm();
        $term->setTaxonomy('category');
        $term->findById(1);

        $data = [
            'name' => 'term \'ok\' \Name',
            'description' => 'Updated \TypeRocket\Name \'in quotes\'',
            'slug' => 'edited-the-term-slug',
            'parent' => 0
        ];

        $term->update($data);

        $name = $term->getProperty('name');
        $description = $term->getProperty('description');
        $slug = $term->getProperty('slug');
        $parent = $term->getProperty('parent');

        $this->assertTrue($name == $data['name']);
        $this->assertTrue($description == $data['description']);
        $this->assertTrue($slug == $data['slug']);
        $this->assertTrue($parent == $data['parent']);
    }
}