<?php

/*
 * This file is part of the Yosymfony\Spress.
 *
 * (c) YoSymfony <http://github.com/yosymfony>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
 
namespace Yosymfony\Spress\ContentLocator;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;
use Yosymfony\Spress\Configuration;

/**
 * @author Victor Puertas <vpgugr@gmail.com>
 * 
 * Locate the content of a site
 */
class ContentLocator
{
    private $configuration;
    private $layoutItems;
    
    /**
     * Constructor
     * 
     * @param Configuration $configuration Configuration manager
     */
    public function __construct(Configuration $configuration)
    {
        if(null === $configuration)
        {
            throw new \InvalidArgumentException('Configuration is null');
        }
        
        $this->configuration = $configuration;
        $this->setCurrentDir($this->getSourceDir());
        $this->createDestinationDirIfNotExists();
    }
    
    /**
     * Get the site posts
     * 
     * @return array Array of FileItem
     */
    public function getPosts()
    {
        $posts = [];
        $markdownExt = $this->configuration->getRepository()->get('markdown_ext');

        if(0 == count($markdownExt))
        {
            return $posts;
        }

        if($this->getPostsDir())
        {
            $finder = new Finder();
            $finder->in($this->getPostsDir())->files();
            $finder->name($this->fileExtToRegExpr($markdownExt));
            
            foreach($finder as $file)
            {
                $posts[] = new FileItem($file, FileItem::TYPE_POST);
            }
        }
        
        return $posts;
    }
    
    /**
     * Get the site pages. Page items is the files with extension in 
     * "processable_ext" key. This method uses "include" and "exclude" keys
     * from config.yml
     * 
     * @return array Array of FileItem
     */
    public function getPages()
    {
        $items = array();
        $includedFiles = array();
        $exclude = $this->configuration->getRepository()->get('exclude');
        $include = $this->configuration->getRepository()->get('include');
        $processableExt = $this->getProcessableExtention();
        
        if(0 == count($processableExt))
        {
            return $items;
        }

        $finder = new Finder();
        $finder->in($this->getSourceDir())->exclude($this->getSpecialDir())->files();
        $finder->name($this->fileExtToRegExpr($processableExt));

        foreach($include as $item)
        {
            if(is_dir($item))
            {
                $finder->in($item);
            }
            else if(is_file($item) && in_array(pathinfo($item, PATHINFO_EXTENSION), $processableExt))
            {
                $includedFiles[] = new SplFileInfo($this->resolvePath($item), "", pathinfo($item, PATHINFO_BASENAME));
            }
        } 
        
        $finder->append($includedFiles);
        
        foreach($exclude as $item)
        {
            $finder->notPath($item);
        }

        foreach($finder as $file)
        {
            $items[] = new FileItem($file, FileItem::TYPE_PAGE);
        }
        
        return $items;
    }
    
    /**
     * Get a filename
     * 
     * @return FileItem
     */
    public function getItem($path)
    {
        $fs = new Filesystem();
        
        if($fs->exists($path))
        {
            $relativePath = $fs->makePathRelative($path, $this->getSourceDir());
            $filename = pathinfo($path, PATHINFO_BASENAME);
            
            if(false !== strpos($relativePath, '..'))
            {
                $relativePath = "";
            }
            
            $fileInfo = new SplFileInfo($path, $relativePath, $relativePath . $filename);
            
            return new FileItem($fileInfo, FileItem::TYPE_PAGE);
        }
    }
    
    /**
     * Get the site layouts
     * 
     * @return array of FileItem
     */
    public function getLayouts()
    {
        $result = [];
        
        if($this->getLayoutsDir())
        {
            $finder = new Finder();
            $finder->in($this->getLayoutsDir())->files();
            
            foreach($finder as $file)
            {
                $result[$file->getRelativePathname()] = new FileItem($file, FileItem::TYPE_PAGE);
            }
        }
        
        return $result;
    }
    
    /**
     * Save a FileItem into destiny paths
     * 
     * @param FileItem $item
     */
    public function saveItem(FileItem $item)
    {
        $fs = new FileSystem();
        $paths = $item->getDestinationPaths();
        
        if(0 == count($paths))
        {
            throw new \LengthException('No destination paths found');
        }
        
        foreach($paths as $destination)
        {
            $fs->dumpFile($this->getDestinationDir() . '/' . $destination, $item->getDestinationContent());
        }
    }
    
    /**
     * Copy the rest files to destination
     * 
     * @return array Filenames affected
     */
    public function copyRestToDestination()
    {
        $fs = new FileSystem();
        $result = array();
        $includedFiles = array();
        $dir = $this->getSourceDir();
        $include = $this->configuration->getRepository()->get('include');
        $exclude = $this->configuration->getRepository()->get('exclude');
        $processableExt = $this->getProcessableExtention();
        
        $finder = new Finder();
        $finder->in($dir)->exclude($this->getSpecialDir());
        $finder->notName($this->configuration->getConfigFilename());
        $finder->notName($this->fileExtToRegExpr($processableExt));
        
        foreach($include as $item)
        {
            if(is_dir($item))
            {
                $finder->in($item);
            }
            else if(is_file($item) && !in_array(pathinfo($item, PATHINFO_EXTENSION), $processableExt))
            {
                $includedFiles[] = new SplFileInfo($this->resolvePath($item), "", pathinfo($item, PATHINFO_BASENAME));
            }
        }
        
        foreach($exclude as $item)
        {
            $finder->notPath($item);
        }
        
        $finder->append($includedFiles);

        foreach($finder as $file)
        {
            if($file->isDir())
            {
                $this->mkDirIfNotExists($this->getDestinationDir() . '/' . $file->getRelativePath());
            }
            else if($file->isFile())
            {
                $result[] = $file->getRealpath();
                $fs->copy($file->getRealpath(), $this->getDestinationDir() . '/' . $file->getRelativePathname());
            }
        }
        
        return $result;
    }
    
    /**
     * Remove all files and directories from destination directory
     * 
     * @return array List of deleted elements
     * 
     * @throw IOException When removal fails
     */
    public function cleanupDestination()
    {
        $fs = new FileSystem();
        $removed = array();
        $destinationDir = $this->configuration->getRepository()->get('destination');
        
        $finder = new Finder();
        $finder->in($destinationDir)
            ->depth('== 0');
        
        $fs->remove($finder);
    }
    
    /**
     * Get the absolute path of posts directory
     * 
     * @return string
     */
    public function getPostsDir()
    {
        return $this->resolvePath($this->configuration->getRepository()->get('posts'));
    }
    
    /**
     * Get the absolute path of source directory
     * 
     * @return string
     */
    public function getSourceDir()
    {
        return $this->configuration->getRepository()->get('source');
    }
    
    /**
     * Get the absolute paths of destination directory
     * 
     * @return string
     */
    public function getDestinationDir($createIfNotExists = true)
    {
        
        return $this->resolvePath($this->configuration->getRepository()->get('destination'));
    }
    
    /**
     * Get the absolute paths of includes directory
     */
    public function getIncludesDir()
    {
        return $this->resolvePath($this->configuration->getRepository()->get('includes'));
    }
    
    /**
     * Get the absolute paths of layouts directory
     */
    public function getLayoutsDir()
    {
        return $this->resolvePath($this->configuration->getRepository()->get('layouts'));
    }
    
    /**
     * Get the processable file's extension
     * See the keys processable_ext and markdown_ext of config.yml
     * 
     * @return array
     */
    public function getProcessableExtention()
    {
        $processableExt = $this->configuration->getRepository()->get('processable_ext');
        $markdownExt = $this->configuration->getRepository()->get('markdown_ext');
        
        return array_unique(array_merge($processableExt, $markdownExt));
    }
    
    private function getPluginDir()
    {
        return $this->configuration->getRepository()->get('plugins');
    }
    
    private function processLayoutData()
    {
        $this->layoutItems = [];
        
        $finder = new Finder();
        $finder->in($this->getLayoutsDir())->files();
        
        foreach($finder as $file)
        {
            $this->layoutItems[] = $file->getRelativePathname();
        }
    }
    
    private function fileExtToRegExpr(array $extensions)
    {
        $list = array_reduce($extensions, function($a, $item){
            return $a . '|' . $item;
        });
        
        return '/\.(' . $list . ')$/';
    }
    
    private function setCurrentDir($path)
    {
        if(false === chdir($path))
        {
            throw new \InvalidArgumentException(sprintf('Error when change the current dir to "%s"', $path));
        }
    }
    
    private function mkDirIfNotExists($path)
    {
        $fs = new FileSystem();
        
        if(!$fs->exists($path))
        {
            $fs->mkdir($path);
        }
    }
    
    /**
     * @return string
     */
    private function resolvePath($path)
    {
        $realPath = realpath($path);
        
        if(false === $realPath)
        {
            return '';
            //throw new \InvalidArgumentException(sprintf('Invalid path "%s"', $path));
        }
        
        return $realPath;
    }
    
    private function makePathRelative($path1, $path2, $normalize = true)
    {
        $fs = new FileSystem();
        $result = $fs->makePathRelative($path1, $path2);
        
        return !$normalize ?: preg_replace('/\/$/', '', $result);
    }
    
    private function getSpecialDir()
    {
        $excludesDir = array();
        $excludesDir[] = $this->makePathRelative($this->getPostsDir(), $this->getSourceDir());
        $excludesDir[] = $this->makePathRelative($this->getLayoutsDir(), $this->getSourceDir());
        $excludesDir[] = $this->makePathRelative($this->getIncludesDir(), $this->getSourceDir());
        $excludesDir[] = $this->makePathRelative($this->getDestinationDir(), $this->getSourceDir());
        $excludesDir[] = $this->makePathRelative($this->getPluginDir(), $this->getSourceDir());
        
        return $excludesDir;
    }
    
    private function createDestinationDirIfNotExists()
    {
        $fs = new Filesystem();
        $destination = $this->configuration->getRepository()->get('destination');
        
        if(false == $fs->exists($destination))
        {
            $fs->mkdir($destination);
        }
    }
}