<?php

it('dds reflection', function () {
    $ref = new ReflectionClass($this);
    dd($ref->getFileName());
    $this->assertTrue(true);
});
