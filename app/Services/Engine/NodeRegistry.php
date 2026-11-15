<?php

namespace App\Services\Engine;

use App\Services\Engine\Nodes\ImageNode;
use App\Services\Engine\Nodes\ManualTriggerNode;
use App\Services\Engine\Nodes\NodeHandler;
use App\Services\Engine\Nodes\ResearchNode;
use App\Services\Engine\Nodes\TextNode;
use App\Services\Engine\Nodes\VideoNode;
use InvalidArgumentException;

class NodeRegistry
{
    public const HANDLERS = [
        'trigger.manual' => ManualTriggerNode::class,
        'ai.text' => TextNode::class,
        'ai.research' => ResearchNode::class,
        'ai.image' => ImageNode::class,
        'ai.video' => VideoNode::class,
    ];

    public function handler(string $type): NodeHandler
    {
        $class = self::HANDLERS[$type] ?? throw new InvalidArgumentException("Unknown node type [{$type}].");

        return app($class);
    }
}
