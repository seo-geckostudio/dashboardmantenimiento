#!/usr/bin/env python3
"""
WordPress Operations MCP Server

This server provides tools for managing WordPress sites through the MCP protocol.
It acts as a bridge between MCP clients and the WordPress maintenance agent.
"""

import asyncio
import json
import logging
import os
import subprocess
import sys
from pathlib import Path
from typing import Any, Dict, List, Optional, Sequence

from mcp.server import Server
from mcp.server.models import InitializationOptions
from mcp.server.stdio import stdio_server
from mcp.types import (
    CallToolRequest,
    CallToolResult,
    ListToolsRequest,
    ListToolsResult,
    TextContent,
    Tool,
)

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("wpops-mcp")

class WordPressOpsServer:
    """WordPress Operations MCP Server"""
    
    def __init__(self):
        self.server = Server("wpops-mcp")
        self.agent_path = os.getenv('AGENT_PATH', '/app/agent')
        self.php_cli = os.getenv('PHP_CLI', 'php')
        
        # Define available tools
        self.tools = [
            Tool(
                name="list_sites",
                description="List WordPress sites with optional filtering",
                inputSchema={
                    "type": "object",
                    "properties": {
                        "status": {
                            "type": "string",
                            "enum": ["active", "inactive", "locked", "all"],
                            "default": "all",
                            "description": "Filter sites by status"
                        },
                        "limit": {
                            "type": "integer",
                            "minimum": 1,
                            "maximum": 1000,
                            "default": 100,
                            "description": "Maximum number of sites to return"
                        }
                    }
                }
            ),
            Tool(
                name="get_site_info",
                description="Get detailed information about a specific WordPress site",
                inputSchema={
                    "type": "object",
                    "properties": {
                        "site_id": {
                            "type": "integer",
                            "description": "Site ID"
                        },
                        "site_path": {
                            "type": "string",
                            "description": "Site path"
                        }
                    },
                    "oneOf": [
                        {"required": ["site_id"]},
                        {"required": ["site_path"]}
                    ]
                }
            ),
            Tool(
                name="lock_site",
                description="Lock a WordPress site to prevent modifications",
                inputSchema={
                    "type": "object",
                    "properties": {
                        "site_id": {
                            "type": "integer",
                            "description": "Site ID"
                        },
                        "site_path": {
                            "type": "string",
                            "description": "Site path"
                        },
                        "message": {
                            "type": "string",
                            "description": "Lock message to display"
                        }
                    },
                    "oneOf": [
                        {"required": ["site_id"]},
                        {"required": ["site_path"]}
                    ]
                }
            ),
            Tool(
                name="unlock_site",
                description="Unlock a WordPress site to allow modifications",
                inputSchema={
                    "type": "object",
                    "properties": {
                        "site_id": {
                            "type": "integer",
                            "description": "Site ID"
                        },
                        "site_path": {
                            "type": "string",
                            "description": "Site path"
                        }
                    },
                    "oneOf": [
                        {"required": ["site_id"]},
                        {"required": ["site_path"]}
                    ]
                }
            ),
            Tool(
                name="harden_site",
                description="Apply security hardening to a WordPress site",
                inputSchema={
                    "type": "object",
                    "properties": {
                        "site_id": {
                            "type": "integer",
                            "description": "Site ID"
                        },
                        "site_path": {
                            "type": "string",
                            "description": "Site path"
                        },
                        "options": {
                            "type": "object",
                            "properties": {
                                "disable_file_editing": {"type": "boolean", "default": True},
                                "hide_wp_version": {"type": "boolean", "default": True},
                                "disable_xmlrpc": {"type": "boolean", "default": True},
                                "limit_login_attempts": {"type": "boolean", "default": True},
                                "secure_wp_config": {"type": "boolean", "default": True}
                            },
                            "description": "Hardening options"
                        }
                    },
                    "oneOf": [
                        {"required": ["site_id"]},
                        {"required": ["site_path"]}
                    ]
                }
            ),
            Tool(
                name="unharden_site",
                description="Remove security hardening from a WordPress site",
                inputSchema={
                    "type": "object",
                    "properties": {
                        "site_id": {
                            "type": "integer",
                            "description": "Site ID"
                        },
                        "site_path": {
                            "type": "string",
                            "description": "Site path"
                        }
                    },
                    "oneOf": [
                        {"required": ["site_id"]},
                        {"required": ["site_path"]}
                    ]
                }
            ),
            Tool(
                name="fix_permissions",
                description="Fix file and directory permissions for a WordPress site",
                inputSchema={
                    "type": "object",
                    "properties": {
                        "site_id": {
                            "type": "integer",
                            "description": "Site ID"
                        },
                        "site_path": {
                            "type": "string",
                            "description": "Site path"
                        },
                        "options": {
                            "type": "object",
                            "properties": {
                                "recursive": {"type": "boolean", "default": True},
                                "dry_run": {"type": "boolean", "default": False},
                                "owner": {"type": "string", "description": "File owner"},
                                "group": {"type": "string", "description": "File group"}
                            },
                            "description": "Permission fixing options"
                        }
                    },
                    "oneOf": [
                        {"required": ["site_id"]},
                        {"required": ["site_path"]}
                    ]
                }
            ),
            Tool(
                name="scan_site",
                description="Scan a WordPress site for security issues and malware",
                inputSchema={
                    "type": "object",
                    "properties": {
                        "site_id": {
                            "type": "integer",
                            "description": "Site ID"
                        },
                        "site_path": {
                            "type": "string",
                            "description": "Site path"
                        },
                        "scan_types": {
                            "type": "array",
                            "items": {
                                "type": "string",
                                "enum": ["malware", "vulnerabilities", "integrity", "permissions", "all"]
                            },
                            "default": ["all"],
                            "description": "Types of scans to perform"
                        }
                    },
                    "oneOf": [
                        {"required": ["site_id"]},
                        {"required": ["site_path"]}
                    ]
                }
            ),
            Tool(
                name="backup_site",
                description="Create a backup of a WordPress site",
                inputSchema={
                    "type": "object",
                    "properties": {
                        "site_id": {
                            "type": "integer",
                            "description": "Site ID"
                        },
                        "site_path": {
                            "type": "string",
                            "description": "Site path"
                        },
                        "backup_type": {
                            "type": "string",
                            "enum": ["full", "files", "database"],
                            "default": "full",
                            "description": "Type of backup to create"
                        },
                        "compression": {
                            "type": "boolean",
                            "default": True,
                            "description": "Compress backup files"
                        }
                    },
                    "oneOf": [
                        {"required": ["site_id"]},
                        {"required": ["site_path"]}
                    ]
                }
            ),
            Tool(
                name="restore_site",
                description="Restore a WordPress site from backup",
                inputSchema={
                    "type": "object",
                    "properties": {
                        "site_id": {
                            "type": "integer",
                            "description": "Site ID"
                        },
                        "site_path": {
                            "type": "string",
                            "description": "Site path"
                        },
                        "backup_id": {
                            "type": "string",
                            "description": "Backup ID to restore from"
                        },
                        "restore_type": {
                            "type": "string",
                            "enum": ["full", "files", "database"],
                            "default": "full",
                            "description": "Type of restore to perform"
                        }
                    },
                    "oneOf": [
                        {"required": ["site_id", "backup_id"]},
                        {"required": ["site_path", "backup_id"]}
                    ]
                }
            ),
            Tool(
                name="update_plugins",
                description="Update WordPress plugins",
                inputSchema={
                    "type": "object",
                    "properties": {
                        "site_id": {
                            "type": "integer",
                            "description": "Site ID"
                        },
                        "site_path": {
                            "type": "string",
                            "description": "Site path"
                        },
                        "plugins": {
                            "type": "array",
                            "items": {"type": "string"},
                            "description": "Specific plugins to update (empty for all)"
                        },
                        "dry_run": {
                            "type": "boolean",
                            "default": False,
                            "description": "Perform a dry run without actual updates"
                        }
                    },
                    "oneOf": [
                        {"required": ["site_id"]},
                        {"required": ["site_path"]}
                    ]
                }
            ),
            Tool(
                name="update_themes",
                description="Update WordPress themes",
                inputSchema={
                    "type": "object",
                    "properties": {
                        "site_id": {
                            "type": "integer",
                            "description": "Site ID"
                        },
                        "site_path": {
                            "type": "string",
                            "description": "Site path"
                        },
                        "themes": {
                            "type": "array",
                            "items": {"type": "string"},
                            "description": "Specific themes to update (empty for all)"
                        },
                        "dry_run": {
                            "type": "boolean",
                            "default": False,
                            "description": "Perform a dry run without actual updates"
                        }
                    },
                    "oneOf": [
                        {"required": ["site_id"]},
                        {"required": ["site_path"]}
                    ]
                }
            ),
            Tool(
                name="update_core",
                description="Update WordPress core",
                inputSchema={
                    "type": "object",
                    "properties": {
                        "site_id": {
                            "type": "integer",
                            "description": "Site ID"
                        },
                        "site_path": {
                            "type": "string",
                            "description": "Site path"
                        },
                        "version": {
                            "type": "string",
                            "description": "Specific version to update to (empty for latest)"
                        },
                        "dry_run": {
                            "type": "boolean",
                            "default": False,
                            "description": "Perform a dry run without actual update"
                        }
                    },
                    "oneOf": [
                        {"required": ["site_id"]},
                        {"required": ["site_path"]}
                    ]
                }
            ),
            Tool(
                name="validate_sites",
                description="Validate WordPress sites integrity and configuration",
                inputSchema={
                    "type": "object",
                    "properties": {
                        "site_id": {
                            "type": "integer",
                            "description": "Site ID (empty for all sites)"
                        },
                        "checks": {
                            "type": "array",
                            "items": {
                                "type": "string",
                                "enum": ["core", "plugins", "themes", "database", "permissions", "all"]
                            },
                            "default": ["all"],
                            "description": "Types of validation checks to perform"
                        }
                    }
                }
            ),
            Tool(
                name="get_jobs",
                description="Get maintenance jobs status and history",
                inputSchema={
                    "type": "object",
                    "properties": {
                        "status": {
                            "type": "string",
                            "enum": ["pending", "running", "completed", "failed", "all"],
                            "default": "all",
                            "description": "Filter jobs by status"
                        },
                        "site_id": {
                            "type": "integer",
                            "description": "Filter jobs by site ID"
                        },
                        "limit": {
                            "type": "integer",
                            "minimum": 1,
                            "maximum": 1000,
                            "default": 100,
                            "description": "Maximum number of jobs to return"
                        }
                    }
                }
            ),
            Tool(
                name="get_dashboard_stats",
                description="Get dashboard statistics and overview",
                inputSchema={
                    "type": "object",
                    "properties": {}
                }
            )
        ]
        
        # Register handlers
        self.server.list_tools = self.list_tools
        self.server.call_tool = self.call_tool

    async def list_tools(self, request: ListToolsRequest) -> ListToolsResult:
        """List available tools"""
        return ListToolsResult(tools=self.tools)

    async def call_tool(self, request: CallToolRequest) -> CallToolResult:
        """Execute a tool"""
        try:
            tool_name = request.params.name
            arguments = request.params.arguments or {}
            
            logger.info(f"Executing tool: {tool_name} with args: {arguments}")
            
            # Route to appropriate handler
            if tool_name == 'list_sites':
                result = await self._list_sites(arguments)
            elif tool_name == 'get_site_info':
                result = await self._get_site_info(arguments)
            elif tool_name == 'lock_site':
                result = await self._lock_site(arguments)
            elif tool_name == 'unlock_site':
                result = await self._unlock_site(arguments)
            elif tool_name == 'harden_site':
                result = await self._harden_site(arguments)
            elif tool_name == 'unharden_site':
                result = await self._unharden_site(arguments)
            elif tool_name == 'fix_permissions':
                result = await self._fix_permissions(arguments)
            elif tool_name == 'scan_site':
                result = await self._scan_site(arguments)
            elif tool_name == 'backup_site':
                result = await self._backup_site(arguments)
            elif tool_name == 'restore_site':
                result = await self._restore_site(arguments)
            elif tool_name == 'update_plugins':
                result = await self._update_plugins(arguments)
            elif tool_name == 'update_themes':
                result = await self._update_themes(arguments)
            elif tool_name == 'update_core':
                result = await self._update_core(arguments)
            elif tool_name == 'validate_sites':
                result = await self._validate_sites(arguments)
            elif tool_name == 'get_jobs':
                result = await self._get_jobs(arguments)
            elif tool_name == 'get_dashboard_stats':
                result = await self._get_dashboard_stats(arguments)
            else:
                raise ValueError(f"Unknown tool: {tool_name}")
            
            return CallToolResult(
                content=[TextContent(type="text", text=json.dumps(result, indent=2))]
            )
            
        except Exception as e:
            logger.error(f"Tool execution failed: {str(e)}")
            return CallToolResult(
                content=[TextContent(type="text", text=json.dumps({
                    "success": False,
                    "error": str(e)
                }, indent=2))],
                isError=True
            )

    async def _execute_agent_command(self, command: str, args: List[str] = None) -> Dict[str, Any]:
        """Execute agent CLI command"""
        if args is None:
            args = []
        
        cmd = [self.php_cli, f"{self.agent_path}/cli.php", command] + args
        
        try:
            process = await asyncio.create_subprocess_exec(
                *cmd,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE,
                cwd=self.agent_path
            )
            
            stdout, stderr = await process.communicate()
            
            if process.returncode == 0:
                try:
                    return json.loads(stdout.decode())
                except json.JSONDecodeError:
                    return {
                        "success": True,
                        "output": stdout.decode(),
                        "error": stderr.decode() if stderr else None
                    }
            else:
                return {
                    "success": False,
                    "error": stderr.decode() if stderr else "Command failed",
                    "exit_code": process.returncode
                }
                
        except Exception as e:
            return {
                "success": False,
                "error": f"Failed to execute command: {str(e)}"
            }

    # Tool implementations
    async def _list_sites(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """List WordPress sites"""
        cmd_args = []
        
        if 'status' in args and args['status'] != 'all':
            cmd_args.extend(['--status', args['status']])
        
        if 'limit' in args:
            cmd_args.extend(['--limit', str(args['limit'])])
        
        return await self._execute_agent_command('sites:list', cmd_args)

    async def _get_site_info(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Get site information"""
        cmd_args = []
        
        if 'site_id' in args:
            cmd_args.extend(['--id', str(args['site_id'])])
        elif 'site_path' in args:
            cmd_args.extend(['--path', args['site_path']])
        else:
            return {"success": False, "error": "Either site_id or site_path is required"}
        
        return await self._execute_agent_command('sites:info', cmd_args)

    async def _lock_site(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Lock a site"""
        cmd_args = []
        
        if 'site_id' in args:
            cmd_args.extend(['--id', str(args['site_id'])])
        elif 'site_path' in args:
            cmd_args.extend(['--path', args['site_path']])
        else:
            return {"success": False, "error": "Either site_id or site_path is required"}
        
        if 'message' in args:
            cmd_args.extend(['--message', args['message']])
        
        return await self._execute_agent_command('sites:lock', cmd_args)

    async def _unlock_site(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Unlock a site"""
        cmd_args = []
        
        if 'site_id' in args:
            cmd_args.extend(['--id', str(args['site_id'])])
        elif 'site_path' in args:
            cmd_args.extend(['--path', args['site_path']])
        else:
            return {"success": False, "error": "Either site_id or site_path is required"}
        
        return await self._execute_agent_command('sites:unlock', cmd_args)

    async def _harden_site(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Harden a site"""
        cmd_args = []
        
        if 'site_id' in args:
            cmd_args.extend(['--id', str(args['site_id'])])
        elif 'site_path' in args:
            cmd_args.extend(['--path', args['site_path']])
        else:
            return {"success": False, "error": "Either site_id or site_path is required"}
        
        if 'options' in args:
            options = args['options']
            for key, value in options.items():
                if isinstance(value, bool):
                    if value:
                        cmd_args.append(f'--{key.replace("_", "-")}')
                    else:
                        cmd_args.append(f'--no-{key.replace("_", "-")}')
        
        return await self._execute_agent_command('sites:harden', cmd_args)

    async def _unharden_site(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Unharden a site"""
        cmd_args = []
        
        if 'site_id' in args:
            cmd_args.extend(['--id', str(args['site_id'])])
        elif 'site_path' in args:
            cmd_args.extend(['--path', args['site_path']])
        else:
            return {"success": False, "error": "Either site_id or site_path is required"}
        
        return await self._execute_agent_command('sites:unharden', cmd_args)

    async def _fix_permissions(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Fix site permissions"""
        cmd_args = []
        
        if 'site_id' in args:
            cmd_args.extend(['--id', str(args['site_id'])])
        elif 'site_path' in args:
            cmd_args.extend(['--path', args['site_path']])
        else:
            return {"success": False, "error": "Either site_id or site_path is required"}
        
        if 'options' in args:
            options = args['options']
            for key, value in options.items():
                if isinstance(value, bool):
                    if value:
                        cmd_args.append(f'--{key.replace("_", "-")}')
                elif isinstance(value, str):
                    cmd_args.extend([f'--{key.replace("_", "-")}', value])
        
        return await self._execute_agent_command('sites:fix-permissions', cmd_args)

    async def _scan_site(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Scan a site"""
        cmd_args = []
        
        if 'site_id' in args:
            cmd_args.extend(['--id', str(args['site_id'])])
        elif 'site_path' in args:
            cmd_args.extend(['--path', args['site_path']])
        else:
            return {"success": False, "error": "Either site_id or site_path is required"}
        
        if 'scan_types' in args:
            for scan_type in args['scan_types']:
                cmd_args.extend(['--scan', scan_type])
        
        return await self._execute_agent_command('sites:scan', cmd_args)

    async def _backup_site(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Backup a site"""
        cmd_args = []
        
        if 'site_id' in args:
            cmd_args.extend(['--id', str(args['site_id'])])
        elif 'site_path' in args:
            cmd_args.extend(['--path', args['site_path']])
        else:
            return {"success": False, "error": "Either site_id or site_path is required"}
        
        if 'backup_type' in args:
            cmd_args.extend(['--type', args['backup_type']])
        
        if 'compression' in args and args['compression']:
            cmd_args.append('--compress')
        
        return await self._execute_agent_command('sites:backup', cmd_args)

    async def _restore_site(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Restore a site"""
        cmd_args = []
        
        if 'site_id' in args:
            cmd_args.extend(['--id', str(args['site_id'])])
        elif 'site_path' in args:
            cmd_args.extend(['--path', args['site_path']])
        else:
            return {"success": False, "error": "Either site_id or site_path is required"}
        
        cmd_args.extend(['--backup-id', args['backup_id']])
        
        if 'restore_type' in args:
            cmd_args.extend(['--type', args['restore_type']])
        
        return await self._execute_agent_command('sites:restore', cmd_args)

    async def _update_plugins(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Update plugins"""
        cmd_args = []
        
        if 'site_id' in args:
            cmd_args.extend(['--id', str(args['site_id'])])
        elif 'site_path' in args:
            cmd_args.extend(['--path', args['site_path']])
        else:
            return {"success": False, "error": "Either site_id or site_path is required"}
        
        if 'plugins' in args and args['plugins']:
            for plugin in args['plugins']:
                cmd_args.extend(['--plugin', plugin])
        
        if 'dry_run' in args and args['dry_run']:
            cmd_args.append('--dry-run')
        
        return await self._execute_agent_command('sites:update-plugins', cmd_args)

    async def _update_themes(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Update themes"""
        cmd_args = []
        
        if 'site_id' in args:
            cmd_args.extend(['--id', str(args['site_id'])])
        elif 'site_path' in args:
            cmd_args.extend(['--path', args['site_path']])
        else:
            return {"success": False, "error": "Either site_id or site_path is required"}
        
        if 'themes' in args and args['themes']:
            for theme in args['themes']:
                cmd_args.extend(['--theme', theme])
        
        if 'dry_run' in args and args['dry_run']:
            cmd_args.append('--dry-run')
        
        return await self._execute_agent_command('sites:update-themes', cmd_args)

    async def _update_core(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Update WordPress core"""
        cmd_args = []
        
        if 'site_id' in args:
            cmd_args.extend(['--id', str(args['site_id'])])
        elif 'site_path' in args:
            cmd_args.extend(['--path', args['site_path']])
        else:
            return {"success": False, "error": "Either site_id or site_path is required"}
        
        if 'version' in args and args['version']:
            cmd_args.extend(['--version', args['version']])
        
        if 'dry_run' in args and args['dry_run']:
            cmd_args.append('--dry-run')
        
        return await self._execute_agent_command('sites:update-core', cmd_args)

    async def _validate_sites(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Validate sites"""
        cmd_args = []
        
        if 'site_id' in args:
            cmd_args.extend(['--id', str(args['site_id'])])
        
        if 'checks' in args:
            for check in args['checks']:
                cmd_args.extend(['--check', check])
        
        return await self._execute_agent_command('validate:sites', cmd_args)

    async def _get_jobs(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Get jobs"""
        cmd_args = []
        
        if 'status' in args and args['status'] != 'all':
            cmd_args.extend(['--status', args['status']])
        
        if 'site_id' in args:
            cmd_args.extend(['--site-id', str(args['site_id'])])
        
        if 'limit' in args:
            cmd_args.extend(['--limit', str(args['limit'])])
        
        return await self._execute_agent_command('jobs:list', cmd_args)

    async def _get_dashboard_stats(self, args: Dict[str, Any]) -> Dict[str, Any]:
        """Get dashboard statistics"""
        return await self._execute_agent_command('dashboard:stats')


async def main():
    """Main entry point"""
    server = WordPressOpsServer()
    
    # Run the server
    async with stdio_server() as (read_stream, write_stream):
        await server.server.run(
            read_stream,
            write_stream,
            InitializationOptions(
                server_name="wpops-mcp",
                server_version="1.0.0",
                capabilities=server.server.get_capabilities(
                    notification_options=None,
                    experimental_capabilities=None,
                ),
            ),
        )


if __name__ == "__main__":
    asyncio.run(main())