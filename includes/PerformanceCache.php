<?php
// includes/PerformanceCache.php

class PerformanceCache {
    private $cacheDir;
    private $defaultTtl = 3600; // 1 hour default
    private $redis = null;
    
    public function __construct($cacheDir = null) {
        $this->cacheDir = $cacheDir ?: BASEPATH . '/cache/performance';
        
        // Create cache directory if it doesn't exist
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
        
        // Try to connect to Redis if available
        if (class_exists('Redis')) {
            try {
                $this->redis = new Redis();
                $this->redis->connect('127.0.0.1', 6379);
                $this->redis->select(1); // Use database 1 for performance cache
            } catch (Exception $e) {
                $this->redis = null;
                logError("Redis connection failed: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Generate cache key based on parameters
     */
    public function generateKey($prefix, $params = []) {
        $keyData = array_merge([$prefix], $params);
        return md5(serialize($keyData));
    }
    
    /**
     * Get cached data
     */
    public function get($key) {
        if ($this->redis) {
            return $this->getFromRedis($key);
        }
        return $this->getFromFile($key);
    }
    
    /**
     * Set cached data
     */
    public function set($key, $data, $ttl = null) {
        $ttl = $ttl ?: $this->defaultTtl;
        
        if ($this->redis) {
            return $this->setToRedis($key, $data, $ttl);
        }
        return $this->setToFile($key, $data, $ttl);
    }
    
    /**
     * Delete cached data
     */
    public function delete($key) {
        if ($this->redis) {
            return $this->redis->del($key);
        }
        
        $filePath = $this->cacheDir . '/' . $key . '.cache';
        if (file_exists($filePath)) {
            return unlink($filePath);
        }
        return true;
    }
    
    /**
     * Clear all cache
     */
    public function clear() {
        if ($this->redis) {
            return $this->redis->flushDB();
        }
        
        $files = glob($this->cacheDir . '/*.cache');
        foreach ($files as $file) {
            unlink($file);
        }
        return true;
    }
    
    /**
     * Get data with cache fallback
     */
    public function remember($key, callable $callback, $ttl = null) {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }
        
        $data = $callback();
        $this->set($key, $data, $ttl);
        return $data;
    }
    
    /**
     * Redis implementation
     */
    private function getFromRedis($key) {
        try {
            $cached = $this->redis->get($key);
            return $cached ? json_decode($cached, true) : null;
        } catch (Exception $e) {
            logError("Redis get error: " . $e->getMessage());
            return null;
        }
    }
    
    private function setToRedis($key, $data, $ttl) {
        try {
            return $this->redis->setex($key, $ttl, json_encode($data));
        } catch (Exception $e) {
            logError("Redis set error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * File-based cache implementation
     */
    private function getFromFile($key) {
        $filePath = $this->cacheDir . '/' . $key . '.cache';
        
        if (!file_exists($filePath)) {
            return null;
        }
        
        $content = file_get_contents($filePath);
        $data = json_decode($content, true);
        
        if (!$data || !isset($data['expires']) || $data['expires'] < time()) {
            unlink($filePath);
            return null;
        }
        
        return $data['data'];
    }
    
    private function setToFile($key, $data, $ttl) {
        $filePath = $this->cacheDir . '/' . $key . '.cache';
        
        $cacheData = [
            'expires' => time() + $ttl,
            'data' => $data
        ];
        
        return file_put_contents($filePath, json_encode($cacheData), LOCK_EX) !== false;
    }
    
    /**
     * Get cache statistics
     */
    public function getStats() {
        if ($this->redis) {
            try {
                $info = $this->redis->info();
                return [
                    'type' => 'redis',
                    'keys' => $this->redis->dbSize(),
                    'memory_usage' => $info['used_memory_human'] ?? 'N/A',
                    'hit_rate' => isset($info['keyspace_hits'], $info['keyspace_misses']) 
                        ? round($info['keyspace_hits'] / ($info['keyspace_hits'] + $info['keyspace_misses']) * 100, 2) . '%'
                        : 'N/A'
                ];
            } catch (Exception $e) {
                return ['type' => 'redis', 'error' => $e->getMessage()];
            }
        }
        
        $files = glob($this->cacheDir . '/*.cache');
        $totalSize = 0;
        $validFiles = 0;
        
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);
            
            if ($data && isset($data['expires']) && $data['expires'] >= time()) {
                $validFiles++;
                $totalSize += filesize($file);
            }
        }
        
        return [
            'type' => 'file',
            'keys' => $validFiles,
            'total_files' => count($files),
            'size' => $this->formatBytes($totalSize)
        ];
    }
    
    private function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}

/**
 * Performance Cache Manager with intelligent invalidation
 */
class PerformanceCacheManager {
    private $cache;
    private $invalidationTags = [];
    
    public function __construct() {
        $this->cache = new PerformanceCache();
    }
    
    /**
     * Cache performance data with intelligent keys
     */
    public function cacheSummaryStats($level, $classId, $subjectId, $semesterId, $data) {
        $key = $this->cache->generateKey('summary_stats', [
            'level' => $level,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'semester_id' => $semesterId
        ]);
        
        $this->cache->set($key, $data, 1800); // 30 minutes for summary stats
        $this->tagKey($key, ['summary', 'level_' . $level, 'class_' . $classId, 'subject_' . $subjectId, 'semester_' . $semesterId]);
        
        return $key;
    }
    
    public function getCachedSummaryStats($level, $classId, $subjectId, $semesterId) {
        $key = $this->cache->generateKey('summary_stats', [
            'level' => $level,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'semester_id' => $semesterId
        ]);
        
        return $this->cache->get($key);
    }
    
    /**
     * Cache top performers data
     */
    public function cacheTopPerformers($level, $classId, $subjectId, $semesterId, $limit, $offset, $data) {
        $key = $this->cache->generateKey('top_performers', [
            'level' => $level,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'semester_id' => $semesterId,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
        $this->cache->set($key, $data, 3600); // 1 hour for top performers
        $this->tagKey($key, ['top_performers', 'level_' . $level, 'class_' . $classId, 'subject_' . $subjectId, 'semester_' . $semesterId]);
        
        return $key;
    }
    
    public function getCachedTopPerformers($level, $classId, $subjectId, $semesterId, $limit, $offset) {
        $key = $this->cache->generateKey('top_performers', [
            'level' => $level,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'semester_id' => $semesterId,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
        return $this->cache->get($key);
    }
    
    /**
     * Cache class performance data
     */
    public function cacheClassPerformance($level, $classId, $subjectId, $semesterId, $limit, $offset, $data) {
        $key = $this->cache->generateKey('class_performance', [
            'level' => $level,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'semester_id' => $semesterId,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
        $this->cache->set($key, $data, 3600); // 1 hour for class performance
        $this->tagKey($key, ['class_performance', 'level_' . $level, 'class_' . $classId, 'subject_' . $subjectId, 'semester_' . $semesterId]);
        
        return $key;
    }
    
    public function getCachedClassPerformance($level, $classId, $subjectId, $semesterId, $limit, $offset) {
        $key = $this->cache->generateKey('class_performance', [
            'level' => $level,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'semester_id' => $semesterId,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
        return $this->cache->get($key);
    }
    
    /**
     * Cache assessment statistics
     */
    public function cacheAssessmentStats($level, $classId, $subjectId, $semesterId, $limit, $offset, $data) {
        $key = $this->cache->generateKey('assessment_stats', [
            'level' => $level,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'semester_id' => $semesterId,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
        $this->cache->set($key, $data, 1800); // 30 minutes for assessment stats
        $this->tagKey($key, ['assessment_stats', 'level_' . $level, 'class_' . $classId, 'subject_' . $subjectId, 'semester_' . $semesterId]);
        
        return $key;
    }
    
    public function getCachedAssessmentStats($level, $classId, $subjectId, $semesterId, $limit, $offset) {
        $key = $this->cache->generateKey('assessment_stats', [
            'level' => $level,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'semester_id' => $semesterId,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
        return $this->cache->get($key);
    }
    
    /**
     * Tag a cache key for intelligent invalidation
     */
    private function tagKey($key, $tags) {
        foreach ($tags as $tag) {
            if (!isset($this->invalidationTags[$tag])) {
                $this->invalidationTags[$tag] = [];
            }
            $this->invalidationTags[$tag][] = $key;
        }
        
        // Store tags in cache for persistence
        $this->cache->set('invalidation_tags', $this->invalidationTags, 86400); // 24 hours
    }
    
    /**
     * Invalidate cache by tags
     */
    public function invalidateByTag($tag) {
        $tags = $this->cache->get('invalidation_tags') ?: [];
        
        if (isset($tags[$tag])) {
            foreach ($tags[$tag] as $key) {
                $this->cache->delete($key);
            }
            
            unset($tags[$tag]);
            $this->cache->set('invalidation_tags', $tags, 86400);
        }
    }
    
    /**
     * Invalidate cache when data changes
     */
    public function invalidateOnDataChange($type, $id = null) {
        switch ($type) {
            case 'result_added':
            case 'result_updated':
                // Invalidate all performance-related cache
                $this->invalidateByTag('summary');
                $this->invalidateByTag('top_performers');
                $this->invalidateByTag('class_performance');
                $this->invalidateByTag('assessment_stats');
                break;
                
            case 'student_added':
            case 'student_updated':
                if ($id) {
                    // Get student's class and invalidate class-specific cache
                    $this->invalidateByTag('class_' . $id);
                }
                $this->invalidateByTag('summary');
                $this->invalidateByTag('assessment_stats');
                break;
                
            case 'assessment_added':
            case 'assessment_updated':
                $this->invalidateByTag('summary');
                $this->invalidateByTag('assessment_stats');
                break;
        }
    }
    
    /**
     * Get cache statistics
     */
    public function getStats() {
        return $this->cache->getStats();
    }
    
    /**
     * Clear all performance cache
     */
    public function clearAll() {
        return $this->cache->clear();
    }
}
?>